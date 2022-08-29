<?php

namespace app\api\controller;

use app\api\BaseController;
use http\Env\Response;

class System extends BaseController
{
    public function time()
    {
        return jok("Hello World!", [
            'time' => intval(microtime(true) * 1000),
        ]);
    }

    public function qrlogin(){
        $result = curlHelper(config('startadmin.163_api')  .  '/login/qr/key?timestamp='  .  time());
        $unikey = json_decode($result['body'], true)['data']['unikey'];
        echo '<a id="newtab" style="visibility: hidden;" href="/api/system/qrcode?unikey='  .  $unikey  .  '" target="_blank"></a>';
        echo '<script  type="text/javascript"> document.getElementById("newtab").click(); </script>';
        return '<script>window.location.replace("/api/system/checklogin?unikey='  .  $unikey  .  '");</script>';
    }

    public function qrcode(){
        if(!input('unikey')) return jerr("缺少unikey", 404);
        $unikey = input('unikey');
        $result = curlHelper(config('startadmin.163_api')  .  '/login/qr/create?key='  .  $unikey  .  '&qrimg=true');
        $qrimg = json_decode($result['body'], true)['data']['qrimg'];
        $imgstring = substr($qrimg, strpos($qrimg, ",") + 1);
        ob_start();
        imagepng(imagecreatefromstring(base64_decode($imgstring)));
        $content = ob_get_clean();
        return response($content, 200, ['Content-Length' => strlen($content)])->contentType('image/png');
    }

    function checkLogin(){
        if (!input('unikey')) return jerr("缺少unikey", 404);
        $unikey = input('unikey');
        $result = curlHelper(config('startadmin.163_api')  .  '/login/qr/check?key='  .  $unikey  .  '&timestamp='  .  time());
        $result = json_decode($result['body'], true);
        switch ($result['code']){
            case '800': return jerr($result['message']); // 过期或不存在
            case '801': {   // 等待扫码
                header("refresh: 5");
                return jok($result['message']);
            }
            case '803':{    // 扫码成功
                $cookie = getCookie(config('startadmin.163_api')  . '/login/qr/check?key='  .  $unikey  .  '&timestamp='  .  time());
                $cookie_keys = array_keys($cookie);
                $cache_cookie = "";

                for ($i = 0; $i <= count($cookie) - 1; $i++) {
                    $cache_cookie .= $cookie_keys[$i] . '=' . $cookie[$cookie_keys[$i]];
                    if ($i != count($cookie) - 1) {
                        $cache_cookie .= '; ';
                    }
                }
                cache('cookie', $cache_cookie, 86400);
                $result = curlHelper(config('startadmin.163_api')  .  '/user/account', 'GET', null, [], $cache_cookie);
                $profile = json_decode($result['body'], true)['profile'];
                $username = $profile['nickname']?:$profile['userName'];

                if (array_key_exists("MUSIC_A_T", $cookie)) {
                    return jok("登陆成功， "   .  $username   .  "!");
                } else {
                    return jerr("登陆失败 :(");
                }
            }
            default: return jerr($result);
        }
    }

    public function login()
    {
        $str = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890';
        $randStr = str_shuffle($str);
        $NMTID = 'NMTID=00O'.substr($randStr,0,39);
        $cookie = getCookie(
            config('startadmin.163_api')  . '/login/cellphone?phone=' . config('startadmin.163_username') . '&md5_password=' . config('startadmin.163_password'),
            $NMTID, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache'
        ]);
        $cookie_keys = array_keys($cookie);
        $cache_cookie = $NMTID."; ";

        for ($i = 0; $i <= count($cookie) - 1; $i++) {
            $cache_cookie .= $cookie_keys[$i] . '=' . $cookie[$cookie_keys[$i]];
            if ($i != count($cookie) - 1) {
                $cache_cookie .= '; ';
            }
        }
        cache('cookie', $cache_cookie, 86400);

        if (array_key_exists("MUSIC_A_T", $cookie) && $cookie['MUSIC_A_T']!="") {
            return jok("Tasty!", [
                'loggedin' => true,
            ]);
        } else {
            return jerr("Sad :(");
        }
    }

    public function refresh_login()
    {
        $cookie = getCookie(
            config('startadmin.163_api') . '/login/refresh',
            cache('cookie'), [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache'
        ]);
        $cookie_keys = array_keys($cookie);
        $cache_cookie = '';

        for ($i = 0; $i <= count($cookie) - 1; $i++) {
            $cache_cookie .= $cookie_keys[$i] . '=' . $cookie[$cookie_keys[$i]];
            if ($i != count($cookie) - 1) {
                $cache_cookie .= '; ';
            }
        }
        cache('cookie', $cache_cookie, 86400);

        if (array_key_exists("MUSIC_A_T", $cookie) && $cookie['MUSIC_A_T']!="") {
            return jok("Tasty!", [
                'refreshed' => "true"
            ]);
        } else {
            return jerr("Sad :(");
        }
    }
}
