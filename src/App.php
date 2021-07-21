<?php

use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Session;
use Workerman\Protocols\Http\Session\RedisSessionHandler;
use Workerman\Worker;

function exception_error_handler($severity, $message, $file, $line)
{
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}

class App
{
    public static $renderer;

    public function __construct($appDir, $appName, $options = [])
    {
        define('APP_DIR', $appDir);
        define('APP_NAME', $appName);
        define('TMP_DIR', '/tmp/'.$appName);
        if (!is_dir(TMP_DIR)) {
            mkdir(TMP_DIR, 0700);
        }
        define('LOG_DIR', APP_DIR.'/log');

        echo sprintf("ENV: %s APP_DIR: %s\n", ENV, APP_DIR);

        spl_autoload_register(function ($class) {
            $path = APP_DIR.'/classes/'.str_replace('_', DIRECTORY_SEPARATOR, $class).'.php';
            if (file_exists($path)) {
                include $path;
            }
        });

        Worker::$pidFile = sprintf('%s/master.pid', LOG_DIR);
        Worker::$logFile = sprintf('%s/workerman.log', LOG_DIR);
        if (PRODUCTION) {
            Worker::$stdoutFile = sprintf('%s/stdout.log', LOG_DIR);
        }

        if (isset($options['renderer'])) {
            App::$renderer = new $options['renderer']();
        }

        Config::load();

        $this->worker         = new Worker(sprintf('unix:///tmp/%s-%s.sock', APP_NAME, ENV)); // socket name cannot >8 chars
        $this->routeCollector = new FastRoute\RouteCollector(
            new FastRoute\RouteParser\Std(),
            new FastRoute\DataGenerator\GroupCountBased()
        );

        $this->setupWorker($options['startup'] ?? null);
    }

    public function run()
    {
        $this->dispatcher = new FastRoute\Dispatcher\GroupCountBased($this->routeCollector->getData());

        Worker::runAll();
    }

    public function get($path, $handler)
    {
        $this->routeCollector->addRoute('GET', $path, $handler);
    }

    public function post($path, $handler)
    {
        $this->routeCollector->addRoute('POST', $path, $handler);
    }

    public function getOrPost($path, $handler)
    {
        $this->routeCollector->addRoute(['GET', 'POST'], $path, $handler);
    }

    public function put($path, $handler)
    {
        $this->routeCollector->addRoute('PUT', $path, $handler);
    }

    public function delete($path, $handler)
    {
        $this->routeCollector->addRoute('DELETE', $path, $handler);
    }

    public function options($path, $handler)
    {
        $this->routeCollector->addRoute('OPTIONS', $path, $handler);
    }

    public function route($method, $path, $handler)
    {
        $this->routeCollector->addRoute($method, $path, $handler);
    }

    public function routeToClass($prefix, $class, $routes)
    {
        $router = $this->routeCollector;
        foreach ($routes as $path => $handler) {
            list($method, $path) = explode(' ', $path, 2);

            if ($prefix && $path === '/') {
                $path = '';
            }
            $path = preg_replace('@/{2,}@', '/', $prefix.$path);

            $router->addRoute($method, $path, [$class, $handler]);
        }
    }

    public function scanRoutes($directory, $classPrefix = null)
    {
        $directory = realpath($directory);
        $it        = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = str_replace(DIRECTORY_SEPARATOR, '_', substr($file->getPathname(), strlen($directory) + 1, -4));
                if ($classPrefix) {
                    $className = $classPrefix.'_'.$className;
                }
                $cls    = new ReflectionClass($className);
                $prefix = '';
                foreach ($cls->getAttributes(Route::class) as $attr) {
                    $prefix = $attr->newInstance()->paths[0];
                }
                foreach ($cls->getMethods() as $method) {
                    foreach ($method->getAttributes(Route::class) as $attr) {
                        $attr = $attr->newInstance();
                        foreach ($attr->paths as $path) {
                            list($methods, $path) = explode(' ', $path, 2);
                            if ($prefix && $path === '/') {
                                $path = '';
                            }
                            $path = preg_replace('@/{2,}@', '/', $prefix.$path);
                            echo sprintf("scanRoute: %s %s\n", $methods, $path);
                            $this->routeCollector->addRoute(explode('|', $methods), $path, [$className, $method->getName()]);
                        }
                    }
                }
            }
        }
    }

    public static function connectRedis($name, $serializer = Redis::SERIALIZER_PHP)
    {
        $redis = new Redis();

        $config = Config::$config[$name];
        $redis->connect($config['host'], $config['port']);
        $redis->select($config['database']);
        $redis->setOption(Redis::OPT_SERIALIZER, $serializer);
        if (isset($config['prefix'])) {
            $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
        }

        return $redis;
    }

    protected function setupWorker(callable $onStart = null)
    {
        $worker                = $this->worker;
        $worker->reusePort     = false;
        $worker->protocol      = '\Workerman\Protocols\Http';
        $worker->count         = PRODUCTION ? (int) (trim(shell_exec('nproc'))) : 2;
        $worker->onWorkerStart = function ($worker) use ($onStart) {
            chmod(sprintf('/tmp/%s-%s.sock', APP_NAME, ENV), 0777);

            Http::SessionName('sid');
            set_error_handler('exception_error_handler');
            Session::handlerClass(RedisSessionHandler::class, Config::get('session'));

            if ($onStart) {
                $onStart();
            }
        };
        $worker->onMessage = function ($conn, $req) {
            $method = $req->method();
            $uri    = $req->uri();

            // remove query string
            $pos = strpos($uri, '?');
            if ($pos !== false) {
                $uri = substr($uri, 0, $pos);
            }
            $uri = rawurldecode($uri);

            $result = $this->dispatcher->dispatch($method, $uri);

            $status = $result[0];
            switch ($status) {
                case FastRoute\Dispatcher::NOT_FOUND:
                $conn->send(new Response(404, [], '<h1>Not Found: '.$uri.'</h1>'));
                break;
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $conn->send(new Response(405, [], '<h1>Method Not Allowed</h1>'));
                break;
            case FastRoute\Dispatcher::FOUND:
                $handler = $result[1];
                $vars    = $result[2];

                try {
                    if (is_array($handler)) {
                        list($cls, $method) = $handler;

                        $obj = new $cls($conn, $req, $method);

                        // process beforeRoute
                        $resp = $obj->beforeRoute();

                        // if beforeRoute does not generate any response, call the handler
                        if ($resp === null || $resp === true) {
                            $resp = $this->callHandler($conn, $req, [$obj, $method], $vars);
                        }
                    } else {
                        $resp = $this->callHandler($conn, $req, $handler, $vars);
                    }

                    // automatically convert array to json; use new StdClass() to send empty object
                    if (is_array($resp) || (is_object($resp) && !($resp instanceof Response))) {
                        $resp = $obj->json($resp);
                    }

                    $conn->send($resp);
                } catch (\Throwable $e) {
                    $messages   = [];
                    $messages[] = '['.date('r').'] '.$req->method().' '.$req->uri();
                    $messages[] = '';
                    $messages[] = $e;
                    if ($req->method() === 'POST') {
                        $messages[] = '';
                        $post       = $req->post();
                        if (!empty($post)) {
                            $messages[] = 'POST '.var_export($post, true);
                        }
                    }
                    $session = $req->session()->all();
                    if (!empty($session)) {
                        $messages[] = '';
                        $messages[] = 'session = '.var_export($session, true);
                    }
                    $message = implode("\n", $messages);

                    fwrite(STDERR, $message."\n"); // development: log to stderr, production: log to Worker::$stdoutFile

                    $conn->send(new Response(500, ['Content-Type' => 'text/plain'], PRODUCTION ? '<h1>Internal Server Error</h1>' : $message));
                }
                break;
            }
        };
    }

    /** Call handler using dependecy injection via parameters */
    protected function callHandler($conn, $request, $handler, $vars)
    {
        static $parameterCache = [];

        if (is_array($handler)) {
            $cacheKey = get_class($handler[0]).'::'.$handler[1];
        } elseif (is_string($handler)) {
            $cacheKey = $handler;
        } elseif ($handler instanceof Closure) {
            $cacheKey = spl_object_hash($handler);
        } else {
            throw new Exception('Unknown handler type: '.$handler);
        }

        if (isset($parameterCache[$cacheKey])) {
            $parameters = $parameterCache[$cacheKey];
        } else {
            $parameters = [];
            $reflect    = is_array($handler) ? new ReflectionMethod($handler[0], $handler[1]) : new ReflectionFunction($handler);
            foreach ($reflect->getParameters() as $param) {
                $opt          = $param->isOptional();
                $parameters[] = [$param->getName(), $opt, $opt ? $param->getDefaultValue() : null];
            }
            $parameterCache[$cacheKey] = $parameters;
        }

        $callParams = [];
        foreach ($parameters as $param) {
            list($name, $isOptional, $default) = $param;
            switch ($name) {
                case 'connection': $callParams = $conn; break;
                case 'request': $callParams[]  = $request; break;
                case 'session': $callParams[]  = $request->session; break;
                case 'get': $callParams[]      = $request->get(); break;
                case 'post': $callParams[]     = $request->post(); break;
                case 'files': $callParams[]    = $request->file(); break;
                default:
                    if (isset($vars[$name])) {
                        $callParams[] = $vars[$name];
                    } elseif (!$isOptional) {
                        return new Response(400, [], '<h1>Bad Request</h1>');
                    } else {
                        $callParams[] = $default;
                    }
                    break;
            }
        }

        return call_user_func_array($handler, $callParams);
    }
}
