<?php

namespace app\api\controller;

use app\api\BaseController;

class System extends BaseController
{
    public function time()
    {
        return jok("Hello World!", [
            'time' => intval(microtime(true) * 1000),
        ]);
    }

    public function login()
    {
        $cookie = getCookie(config('startadmin.163_api')  . '/login/cellphone?phone=' . config('startadmin.163_username') . '&md5_password=' . config('startadmin.163_password'));
        $cache_cookie = json_encode($cookie);
        cache('cookie', $cache_cookie, 86400);

        if ($cookie['__remember_me'] == 'true') {
            return jok("Tasty!", [
                'loggedin' => $cookie['__remember_me'],
            ]);
        } else {
            return jerr("Sad :(");
        }
    }
}
