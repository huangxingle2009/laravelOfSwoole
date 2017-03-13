<?php
use Illuminate\Http\Request as IlluminateRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
class Server
{
    private $swoole_http_server;
    private $laravel_kernel;
    //创建swoole http服务器
    function __construct($host, $port)
    {
        $this->swoole_http_server = new swoole_http_server($host, $port);

        //swoole配置项
        $this->swoole_http_server->set([
            'max_conn' => 1024,
            'timeout' => 2.5,
            'poll_thread_num' => 8, //reactor thread num
            'writer_num' => 8,     //writer thread num
            'worker_num' => 8,    //worker process num
            'max_request' => 4000,
            'dispatch_mode' => 1,
            'log_file' => __DIR__ . '/log',
            'daemonize' => 1//异步 0：同步
        ]);
    }

    public function start()
    {
        //注册workerStart回调
        $this->swoole_http_server->on('WorkerStart', array($this, 'onWorkerStart'));
        //注册request回调
        $this->swoole_http_server->on('request', array($this, 'onRequest'));

        //启动swoole
        $this->swoole_http_server->start();
    }

    public function onWorkerStart($serv, $worker_id)
    {
        require __DIR__ . '/../bootstrap/autoload.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $this->laravel_kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

        Illuminate\Http\Request::enableHttpMethodParameterOverride();
    }

    public function onRequest($request, $response)
    {
        $new_header = [];
        $uc_header = [];
        //$_server consist of  $request->server + $request->header
        foreach ($request->header as $key => $value) {
            $new_header['http_' . $key] = $value;
            $uc_header[ucwords($key, '-')] = $value;
        }
        $server = array_merge($request->server, $new_header);
        // swoole has changed all keys to lower case
        $server = array_change_key_case($server, CASE_UPPER);
        $request->server = $server;
        $request->header = $uc_header;
        
        // 转换为Swoole的请求对象
        $get = isset($request->get) ? $request->get : array();
        $post = isset($request->post) ? $request->post : array();
        $cookie = isset($request->cookie) ? $request->cookie : array();
        $files = isset($request->files) ? $request->files : array();
        $content = $request->rawContent() ?: null;
        // 创建illuminate_request
        $illuminate_request = IlluminateRequest::createFromBase(
            new SymfonyRequest($get, $post, array(), $cookie, $files, $server, $content)
        );
        $_SERVER = array_merge($_SERVER, $request->server); 
        //把illuminate_request传入laravel_kernel后，取回illuminate_response
        $illuminate_response = $this->laravel_kernel->handle($illuminate_request);

        // 转换为Swoole的响应对象
        // status
        $response->status($illuminate_response->getStatusCode());
        // headers
        foreach ($illuminate_response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }
        // Cookies
        foreach ($illuminate_response->headers->getCookies() as $cookie) {
            $response->cookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
        }
        // Content
        $content = $illuminate_response->getContent();
        // Send content & Close
        $response->end($content);

        // 结束请求
        $this->laravel_kernel->terminate($illuminate_request, $illuminate_response);
    }
}
$http = new Server('127.0.0.1', 9050);
$http->start();
