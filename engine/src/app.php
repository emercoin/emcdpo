<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\Loader\YamlFileLoader;

require_once __DIR__.'/settings.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/emercoin.php';

$app = new Silex\Application();

$app['debug'] = IN_DEBUG_MODE;

$app->register(
    new Silex\Provider\TwigServiceProvider(),
    array(
        'twig.path' => __DIR__.'/views',
    )
);

$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\LocaleServiceProvider());
$app->register(
    new Silex\Provider\TranslationServiceProvider(),
    array(
        'locale_fallbacks' => array('en'),
        'translator.domains' => array(),
    )
);

$app->extend(
    'translator',
    function ($translator, $app) {
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__.'/locales/en.yml', 'en');

        return $translator;
    }
);


class SettingsErrorHttpException extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    function __construct()
    {
        parent::__construct(500, 'Settings error');
    }
}

$app->before(
    function (Request $request) use ($app) {
        $locale = null;
        if ($app['session']->has('locale')) {
            $locale = $app['session']->get('locale');
        } else {
            $locale = substr($request->server->get('HTTP_ACCEPT_LANGUAGE'), 0, 2);
            $app['session']->set('locale', $locale);
        }
        $app['translator']->addResource('yaml', __DIR__.'/locales/'.$locale.'.yml', $locale);
        $app['translator']->setLocale($locale);

        try {
            $req = new \Emercoin\Request('name_show', ['dpo:'.DPO_VENDOR]);
            $app['emercoin.dpo.vendor'] = new \Emercoin\Key($req->getData());

            $app->extend(
                'twig',
                function ($twig, $app) {
                    $twig->addGlobal('ALLOWED_UPDATES', ALLOWED_UPDATES);
                    $twig->addGlobal('EMERCOIN_DPO_VENDOR', $app['emercoin.dpo.vendor']);
                    $twig->addGlobal('EMERCOIN_DPO_VENDOR_ID', DPO_VENDOR);

                    $twig->addGlobal('PHOTO', PHOTO);
                    $twig->addGlobal('SIGNATURE', SIGNATURE);
                    $twig->addGlobal('COMMENT', COMMENT);
                    $twig->addGlobal('OWNER', OWNER);
                    $twig->addGlobal('SECRET', SECRET);
                    $twig->addGlobal('OTP', OTP);
                    $twig->addGlobal('UPDATED', UPDATED);
                    $twig->addGlobal('NAME', NAME);
                    $twig->addGlobal('ITEM', ITEM);
                    $twig->addGlobal('LOGO', LOGO);

                    return $twig;
                }
            );
        } catch (\Emercoin\WalletConnectException $e) {
            throw new SettingsErrorHttpException();
        }
    }
);

$app->error(
    function (\SettingsErrorHttpException $e, Request $request, $code) use ($app) {
        return $app['twig']->render('settings_error.twig', []);
    }
);

$app->get(
    '/',
    function (Request $request) use ($app) {
        $key = $request->query->get('key');
        $otp = $request->query->get('otp');

        if ($key && $otp) {
            return $app->redirect($app['url_generator']->generate('show_key', ['key' => $key, 'otp' => $otp]));
        } else {
            if ($key) {
                return $app->redirect($app['url_generator']->generate('show_key', ['key' => $key]));
            }
        }

        return $app['twig']->render('index.twig', array());
    }
)->bind('_index_');

$app->get(
    '/setlocale/{locale}',
    function (Silex\Application $app, $locale, Request $req) {

        $app['translator']->setLocale($locale);
        $app['session']->set('locale', $locale);
        if($req->headers->get('referer')) {
            return $app->redirect($req->headers->get('referer'));
        } else {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
    }
)->bind('setlocale');;

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
            return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.key_not_found')]);
        }

        if ($emcKey->hasOTP() && !$emcKey->hasSecret() && $req->query->get('otp') !== null) {
            if (!$emcKey->compareOtp($req->query->get('otp'))) {
                return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.wrong_otp')]);
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
            return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.key_not_found')]);
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

                    return $app['twig']->render(
                        'error.twig',
                        [
                            'error' => $app['translator']->trans('error.smth_went_wrong'),
                        ]
                    );
                }

                return $app['twig']->render(
                    'error.twig',
                    [
                        'error' => $app['translator']->trans('error.password_mismatch'),
                    ]
                );
            }

            return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.wrong_otp')]);
        }

        return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.action_is_forbidden')]);
    }
)->bind('activation');

$app->get(
    '/key/{key}/activate',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        return $app['twig']->render('activate.twig', ['key' => $emcKey]);
    }
)->bind('activate');

$app->post(
    '/key/{key}/comment',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        if (!$emcKey) {
            return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.key_not_found')]);
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

                return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.while_saving')]);
            }

            return $app['twig']->render(
                'error.twig',
                [
                    'error' => $app['translator']->trans('error.wrong_password_or_limit_exceed'),
                ]
            );
        }

        return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.action_is_forbidden')]);
    }
)->bind('comment');

$app->get(
    '/key/{key}/leave_comment',
    function (Silex\Application $app, $key, Request $req) {
        $m = new Emercoin\Manager();
        $emcKey = $m->getKey($key);

        if (!$emcKey) {
            return $app['twig']->render('error.twig', ['error' => $app['translator']->trans('error.key_not_found')]);
        }

        return $app['twig']->render('comment.twig', ['key' => $emcKey]);
    }
)->bind('leave_comment');

return $app;
