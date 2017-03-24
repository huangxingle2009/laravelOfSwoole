# laravelOfSwoole
laravel  5.3 整合 laravel
开启 :
php swoole.php

下载方法：
if (env("RUN_MODEL") == 'SWOOLE'){
    return response('x-type=download&' . storage_path($zip_path));
}

return response()->download(storage_path($zip_path));
