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
            die('Settings Error');
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

        if ($key) {
            return $app->redirect($app['url_generator']->generate('show_key', ['key' => $key]));
        }

        return $app['twig']->render('index.twig', array());
    }
)->bind('_index_');

$app->get(
    '/key/{key}',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $ek = $m->getKey($key);

        if (!$ek) { // not found
            return $app['twig']->render('key_notfound.twig');
        }

        $firstOwner = null;
        if ($ek->hasSecret()) {
            /** @var Emercoin\Key $value */
            foreach ($ek->getHistory() as $value) {
                if ($value->hasOwner()) {
                    $firstOwner = $value;
                    break;
                }
            }
        }

        return $app['twig']->render(
            'key.twig',
            array(
                'key' => $ek,
                'first_owner' => $firstOwner,
            )
        );
    }
)->bind('show_key');

$app->post(
    '/key/{key}/activation',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        /** @var Emercoin\Key $ek */
        $ek = $m->getKey($key);

        if (!$ek) { // not found
            return $app['twig']->render('key_notfound.twig');
        }

        $form = [
            'otp' => $req->get('otp'),
            'password_repeat' => $req->get('password_repeat'),
            'password' => $req->get('password'),
            'comment' => $req->get('comment'),
            'owner' => $req->get('owner'),
        ];

        if ($ek->hasOTP() && !$ek->hasSecret()) {
            if ($ek->compareOtp($form['otp'])) {
                if ($form['password'] === $form['password_repeat']) {
                    $ek->setOwner($form['owner']);
                    $ek->setSecret($form['password']);
                    $ek->setComment($form['comment']);
                    $ek->incrementUpdates();

                    $result = $m->saveKey($ek);

                    if ($result) {
                        return $app['twig']->render('key_activated.twig', []);
                    }

                    return $app['twig']->render('key_activated.twig', ['error' => 'Error occurred while saving data']);
                }

                return $app['twig']->render('key_activated.twig', ['error' => 'Password mismatch']);
            }

            return $app['twig']->render('key_activated.twig', ['error' => 'Wrong OTP']);
        }

        return $app['twig']->render('forbidden.twig', array());
    }
)->bind('activation');

$app->post(
    '/key/{key}/comment',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $ek = $m->getKey($key);

        if (!$ek) {
            return $app['twig']->render('key_notfound.twig');
        }

        $form = [
            'password' => $req->get('password'),
            'comment' => $req->get('comment'),
        ];

        if ($ek->hasSecret()) {
            $password = $form['password'];
            if ($ek->compareSecret($password) && $ek->getUpdated() < ALLOWED_UPDATES) {
                $ek->setComment($form['comment']);
                $ek->incrementUpdates();
                $result = $m->saveKey($ek);

                if ($result) {
                    return $app['twig']->render('left_comment.twig', []);
                }

                return $app['twig']->render('left_comment.twig', ['error' => 'Error occurred while saving data']);
            }

            return $app['twig']->render('left_comment.twig', ['error' => 'Wrong password or exceed limit of updates']);
        }

        return $app['twig']->render('forbidden.twig', []);
    }
)->bind('comment');

return $app;
