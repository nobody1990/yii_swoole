<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/9/6
 * Time: 下午3:45
 */

namespace nobody\swoole\bootstrap;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
// use nobody\swoole\web\Application;


use nobody\swoole\di\Container;
use nobody\swoole\di\ContainerDecorator;
use nobody\swoole\di\Context;
use nobody\swoole\server\Server;
use Yii;
use nobody\swoole\bootstrap\WebApp;

// defined('COROUTINE_ENV') or define('COROUTINE_ENV', false);

abstract class BaseBootstrap implements BootstrapInterface
{
    public $index = '/index.php';
    /**
     * @var Server
     */
    protected $server;

    public $appConfig;

    /**
     * @var callable
     */
    public $init;
    /**
     * @var int worker线程的ID
     */
    protected $workerId;
    /**
     * @var Container
     */
    protected static $container;

    public function __construct(Server $server = null)
    {
        $this->server = $server;
        $this->init();
    }

    public function init()
    {
    }

    public static function getContainer()
    {
        return static::$container;
    }

    public function getServer()
    {
        return $this->server;
    }

    /**
     * 在该方法中实际处理请求
     * @param $request
     * @param $response
     * @return mixed
     */
    public abstract function handleRequest($request, $response);

    /**
     * 根据请求,构建Yii的环境变量
     * @param SwooleRequest $request
     */
    protected function initRequest($request)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        // file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. var_export($request, true). PHP_EOL, FILE_APPEND);

        //websocket时,$request为null
        //websockerapp->onRequest(null, null);
        if ($request) {
            $request->get = isset($request->get) ? $request->get : [];
            $request->post = isset($request->post) ? $request->post : [];
            $request->header = isset($request->header) ? $request->header : [];
            $request->cookie = isset($request->cookie) ? $request->cookie : [];
            $request->files = isset($request->files) ? $request->files : [];
            $request->server = isset($request->server) ? $request->server : [];
            $request->server['REQUEST_URI'] = isset($request->server['request_uri']) ? $request->server['request_uri'] : '';

            $request->server = array_change_key_case($request->server, CASE_UPPER);
        }
    }

    public function onWorkerStart($server, $worker_id)
    {
        // Server::getLog()->info(__METHOD__);
        // $this->
        // global $mlog;
        // $mlog->info(__METHOD__);
        $this->workerId = $worker_id;
        $initFunc = $this->init;
        if ($initFunc instanceof \Closure) {
            $initFunc($this);
        }

        $this->initServerVars();
        //在进程中保持引用关系,使持久化类不受context->removeCoroutineData影响,而被回收
        if (COROUTINE_ENV) {
            self::$container = new Container();
            Yii::$container = new ContainerDecorator();
            Yii::$context = new Context();
        } else {
            self::$container = new \nobody\swoole\di\cm\Container();
            Yii::$container = self::$container;
        }
        //由于logger为全局变量,但容器被设置为上下文相关,导致出现logger类在某些情况下无协程ID导致无法初始化问题
        Yii::setLogger(self::$container->get('yii\log\Logger'));
    }

    /**
     * @inheritdoc
     */
    public function onRequest($request, $response)
    {

        $this->initRequest($request);
        if (COROUTINE_ENV) {
            //协程环境每次都初始化容器,以做协程隔离
            Yii::$context->setContainer(new Container());
        }

        $result = $this->handleRequest($request, $response);

        if (COROUTINE_ENV) {
            Yii::$context->removeCurrentCoroutineData();
        }
        return $result;
    }


    /**
     * @inheritdoc
     */
    public function onRequests($request, $response)
    {

        $this->initRequest($request);
        if (COROUTINE_ENV) {
            //协程环境每次都初始化容器,以做协程隔离
            Yii::$context->setContainer(new Container());
        }

        $result = $this->handleRequest($request, $response);
        if (COROUTINE_ENV) {
            Yii::$context->removeCurrentCoroutineData();
        }
        // Yii::info(__METHOD__. var_export($result, true));
        return $result;
    }

    public function onWorkerError($swooleServer, $workerId, $workerPid, $exitCode, $sigNo)
    {
        Yii::error("worker error happening [workerId=$workerId, workerPid=$workerPid, exitCode=$exitCode, signalNo=$sigNo]...", 'monitor');
    }

    /**
     * @TODO 这里待完善
     * [onTask description]
     * @param  [type] $server      [description]
     * @param  [type] $taskId      [description]
     * @param  [type] $srcWorkerId [description]
     * @param  [type] $data        [description]
     * @return [type]              [description]
     */
    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        $func = array_shift($data);
        if (is_callable($func)) {
            $params[] = array_shift($data);
            call_user_func_array($func, $params);
        }
        return 1;
    }

    public function onFinish($server, $taskId, $data)
    {
        //echo $data;
    }

    /**
     * @param \Swoole\Server $server
     * @param $worker_id
     */
    public function onWorkerStop($server, $worker_id)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);
        // $mlog->info('');

        $logger = Yii::getLogger();
        $logger->flush(true);
    }

    /**
     * 初始化Yii框架所需要的$_SERVER变量以及执行过程中所需要的通用变量
     */
    protected function initServerVars()
    {
        //使application运行时不会报错
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['DOCUMENT_URI'] = $this->index;
        $_SERVER['SCRIPT_FILENAME'] = ($this->server ? $this->server->root : '') . $this->index;

        $_SERVER['WORKER_ID'] = $this->workerId;
    }
}