<?php

use Symfony\Component\HttpFoundation\Request;

//use Silex\Provider\FormServiceProvider;
//use Symfony\Component\Validator\Constraints as Assert;
//use Symfony\Component\Form\Extension\Core\Type;

require_once __DIR__.'/settings.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/emercoin.php';

$app = new Silex\Application();
$app['debug'] = true;
$app->register(
    new Silex\Provider\TwigServiceProvider(),
    array(
        'twig.path' => __DIR__.'/views',
    )
);
//$app->register(new Silex\Provider\ValidatorServiceProvider());
//$app->register(new Silex\Provider\LocaleServiceProvider());
//$app->register(
//    new Silex\Provider\TranslationServiceProvider(),
//    array(
//        'locale_fallbacks' => array('en'),
//        'translator.domains' => array(),
//    )
//);
//$app->register(new FormServiceProvider());

$app->before(
    function () use ($app) {
        try {
            $req = new \Emercoin\Request('name_show', ['dpo:'.DPO_VENDOR]);
            $app['emercoin.dpo.vendor'] = new \Emercoin\Key($req->getData());
        } catch (\Emercoin\WalletConnectException $e) {
            require_once('views/settings_error.html');
	    exit;
        }

        $app->extend(
            'twig',
            function ($twig, $app) {
                $twig->addGlobal('ALLOWED_UPDATES', ALLOWED_UPDATES);
                $twig->addGlobal('EMERCOIN_DPO_VENDOR', $app['emercoin.dpo.vendor']);
                $twig->addGlobal('EMERCOIN_DPO_VENDOR_ID', DPO_VENDOR);

                return $twig;
            }
        );
    }
);

$app->get(
    '/',
    function (Request $request) use ($app) {
        $key = $request->query->get('key');
        $otp = $request->query->get('otp');

        if ($key && $otp) {
            return $app->redirect($app['url_generator']->generate('show_key', ['key' => $key, 'otp' => $otp]));
        } else if ($key) {
            return $app->redirect($app['url_generator']->generate('show_key', ['key' => $key]));
        }

        return $app['twig']->render('index.twig', array());
    }
)->bind('_index_');

$app->get(
    '/check_key/{key}',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

 	return var_export(isset($emcKey), true);
 	//return isset($emcKey) ? 'true' : 'false';
    }
)->bind('check_key');

$app->get(
    '/key/{key}',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        if (!$emcKey) {
            return $app['twig']->render('error.twig', ['error' => 'This key cannot be found']);
        }

	if ($emcKey->hasOTP() && !$emcKey->hasSecret() && $req->query->get('otp') !== null) {
	    if (!$emcKey->compareOtp($req->query->get('otp'))) {
    	        return $app['twig']->render('error.twig', ['error' => 'Wrong OTP']);
	    }
	}

        $firstOwner = null;
        if ($emcKey->hasSecret()) {
            /** @var Emercoin\Key $value */
            foreach ($emcKey->getHistory() as $value) {
                if ($value->hasOwner()) {
                    $firstOwner = $value;
                    break;
                }
            }
        }

        return $app['twig']->render(
            'key.twig',
            array(
                'key' => $emcKey,
                'first_owner' => $firstOwner,
            )
        );
    }
)->bind('show_key');

$app->post(
    '/key/{key}/activation',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        /** @var Emercoin\Key $emcKey */
        $emcKey = $m->getKey($key);

        if (!$emcKey) {
            return $app['twig']->render('error.twig', ['error' => 'This key cannot be found']);
        }

        $form = [
            'otp' => $req->get('otp'),
            'password_repeat' => $req->get('password_repeat'),
            'password' => $req->get('password'),
            'comment' => $req->get('comment'),
            'owner' => $req->get('owner'),
        ];

        if ($emcKey->hasOTP() && !$emcKey->hasSecret()) {
            if ($emcKey->compareOtp($form['otp'])) {
                if ($form['password'] === $form['password_repeat']) {
                    $emcKey->setOwner($form['owner']);
                    $emcKey->setSecret($form['password']);
                    $emcKey->setComment($form['comment']);
                    $emcKey->incrementUpdates();

                    $result = @$m->saveKey($emcKey);

                    if ($result) {
                        return $app['twig']->render('key_activated.twig', []);
                    }

                    return $app['twig']->render('error.twig', ['error' => 'Something went wrong. Please wait for a while and try again.']);
                }

                return $app['twig']->render('error.twig', ['error' => 'Password mismatch']);
            }

            return $app['twig']->render('error.twig', ['error' => 'Wrong OTP']);
        }

        return $app['twig']->render('error.twig', ['error' => 'This action is forbidden']);
    }
)->bind('activation');

$app->get(
    '/key/{key}/activate',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        return $app['twig']->render('activate.twig', [ 'key' => $emcKey ]);
    }
)->bind('activate');

$app->post(
    '/key/{key}/comment',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        if (!$emcKey) {
	    return $app['twig']->render('error.twig', ['error' => 'This key cannot be found']);
        }

        $form = [
            'password' => $req->get('password'),
            'comment' => $req->get('comment'),
        ];

        if ($emcKey->hasSecret()) {
            $password = $form['password'];
            if ($emcKey->compareSecret($password) && $emcKey->getUpdated() < ALLOWED_UPDATES) {
                $emcKey->setComment($form['comment']);
                $emcKey->incrementUpdates();

                $result = @$m->saveKey($emcKey);

                if ($result) {
                    return $app['twig']->render('left_comment.twig', []);
                }

                return $app['twig']->render('error.twig', ['error' => 'Error occurred while saving data']);
            }

            return $app['twig']->render('error.twig', ['error' => 'Wrong password or exceed limit of updates']);
        }

        return $app['twig']->render('error.twig', ['error' => 'This action is forbidden']);
    }
)->bind('comment');

$app->get(
    '/key/{key}/leave_comment',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        return $app['twig']->render('comment.twig', [ 'key' => $emcKey ]);
    }
)->bind('leave_comment');

return $app;
