<?php
/**
 * Created by PhpStorm.
 * User: liangchen
 * Date: 2018/2/11
 * Time: 下午3:50.
 */

namespace Tars\core;

use Tars\App;
use Tars\Consts;
use Tars\protocol\ProtocolFactory;
use Tars\monitor\StatFServer;
use Tars\monitor\PropertyFServer;
use Tars\report\ServerFWrapper;
use Tars\config\ConfigWrapper;
use Tars\monitor\cache\SwooleTableStoreCache;

class Server
{
    protected $tarsConfig;
    private $tarsServerConfig;
    private $tarsClientConfig;

    protected $sw;
    protected $masterPidFile;
    protected $managerPidFile;

    protected $application;
    protected $serverName = '';
    protected $protocolName = 'tars';

    protected $host = '0.0.0.0';
    protected $port = '8088';
    protected $worker_num = 4;
    protected $servType = 'tcp';

    protected $setting;

    protected $servicesInfo;
    protected static $paramInfos;
    protected $namespaceName;
    protected $executeClass;

    protected static $impl;
    protected $protocol;
    protected $timers;

    public function __construct($conf, $table = null)
    {
        $this->tarsServerConfig = $conf['tars']['application']['server'];
        $this->tarsClientConfig = $conf['tars']['application']['client'];

        $this->servicesInfo = $this->tarsServerConfig['servicesInfo'];

        $this->tarsConfig = $conf;
        $this->application = $this->tarsServerConfig['app'];
        $this->serverName = $this->tarsServerConfig['server'];

        $this->host = $this->tarsServerConfig['listen'][0]['bIp'];
        $this->port = $this->tarsServerConfig['listen'][0]['iPort'];

        $this->setting = $this->tarsServerConfig['setting'];

        $this->protocolName = $this->tarsServerConfig['protocolName'];
        $this->servType = $this->tarsServerConfig['servType'];
        $this->table = $table;
        $this->worker_num = $this->setting['worker_num'];
    }

    public function start()
    {
        $interval           =   $this->tarsClientConfig['report-interval'];
        $statServantName    =   $this->tarsClientConfig['stat'];
        $locator = $this->tarsClientConfig['locator'];
        $moduleName = $this->application . '.' . $this->serverName;

        // 初始化被调上报
        $statF = new StatFServer($locator, Consts::SWOOLE_SYNC_MODE,
            $statServantName, $moduleName,
            $interval);

        $monitorStoreClassName =
            isset($servicesInfo['monitorStoreConf']['className']) ?
                $servicesInfo['monitorStoreConf']['className'] :
                SwooleTableStoreCache::class;

        $monitorStoreConfig = isset($servicesInfo['monitorStoreConf']['config'])
            ? $servicesInfo['monitorStoreConf']['config'] : [];

        $storeCache = new $monitorStoreClassName($monitorStoreConfig);
        $statF->initStoreCache($storeCache);

        //初始化特性上报
        $propertyF = new PropertyFServer($locator, Consts::SWOOLE_SYNC_MODE,
            $moduleName);

        // 初始化服务保活
        // 解析出node上报的配置 tars.tarsnode.ServerObj@tcp -h 127.0.0.1 -p 2345 -t 10000
        $result = \Tars\Utils::parseNodeInfo($this->tarsServerConf['node']);
        $objName = $result['objName'];
        $host = $result['host'];
        $port = $result['port'];
        $serverF = new ServerFWrapper($host, $port, $objName);

        // 配置拉取初始化
        $configF = new ConfigWrapper($this->tarsClientConfig);

        // 日志组件初始化 todo 根据平台配置的level来
        $logger = new \Monolog\Logger("tars_logger");
        $outStreamHandler = new \Monolog\Handler\StreamHandler(
            $this->setting['log_path'] . "/stdout.log");



        $logger->pushHandler($outStreamHandler);


        // 初始化
        App::setTarsConfig($this->tarsConfig);
        App::setStatF($statF);
        App::setPropertyF($propertyF);
        App::setServerF($serverF);
        App::setConfigF($configF);


        switch ($this->servType) {
            case 'http' : {
                $swooleServerName = '\swoole_http_server';
                break;
            }
            case 'websocket' : {
                $swooleServerName = '\swoole_websocket_server';
                break;
            }
            default : {
                $swooleServerName = '\swoole_server';
                break;
            }
        }

        $this->sw = new $swooleServerName($this->host, $this->port,
            SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $this->sw->servType = $this->servType;

        if ($this->servType == 'http') {
            $this->sw->on('Request', array($this, 'onRequest'));

            // 判断是否是timer服务
            if (isset($this->tarsServerConfig['isTimer']) && $this->tarsServerConfig['isTimer'] == true) {

                $timerDir = $this->tarsServerConfig['basepath'] . 'src/timer/';

                if (is_dir($timerDir)) {
                    $files = scandir($timerDir);
                    foreach ($files as $f) {
                        $fileName = $timerDir . $f;
                        if (is_file($fileName) && strrchr($fileName, '.php') == '.php') {
                            $this->timers[] = $fileName;
                        }
                    }
                } else {
                    error_log(__METHOD__ .' Timer directory is missing');
                }
            }
        }
        else if($this->servType == 'websocket') {
            $this->sw->on('Request', array($this, 'onRequest'));
            $this->sw->on('Message', array($this, 'onMessage'));
        }

        $this->sw->set($this->setting);

        $this->sw->on('Start', array($this, 'onMasterStart'));
        $this->sw->on('ManagerStart', array($this, 'onManagerStart'));
        $this->sw->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->sw->on('Connect', array($this, 'onConnect'));
        $this->sw->on('Receive', array($this, 'onReceive'));
        $this->sw->on('Close', array($this, 'onClose'));
        $this->sw->on('WorkerStop', array($this, 'onWorkerStop'));

        $this->sw->on('Task', array($this, 'onTask'));
        $this->sw->on('Finish', array($this, 'onFinish'));

        // todo 之后去掉
        if (!is_null($this->table)) {
            $this->sw->table = $this->table;
        }

        $this->masterPidFile = $this->tarsServerConfig['datapath'] . '/master.pid';
        $this->managerPidFile = $this->tarsServerConfig['datapath'] . '/manager.pid';

        $this->protocol = ProtocolFactory::getProtocol($this->protocolName);

        require_once $this->tarsServerConfig['entrance'];

        $this->sw->start();
    }

    public function stop()
    {
    }

    public function restart()
    {
    }

    public function reload()
    {
    }

    public function onConnect($server, $fd, $fromId)
    {
    }

    public function onFinish($server, $taskId, $data)
    {
    }

    public function onClose($server, $fd, $fromId)
    {
    }

    public function onWorkerStop($server, $workerId)
    {
    }

    public function onTimer($server, $interval)
    {
    }

    public function onMasterStart($server)
    {
        $this->_setProcessName($this->application . '.'
            . $this->serverName . ': master process');
        file_put_contents($this->masterPidFile, $server->master_pid);
        file_put_contents($this->managerPidFile, $server->manager_pid);

        // 初始化的一次上报
        TarsPlatform::keepaliveInit($this->tarsConfig, $server->master_pid);

        //拉取配置
        if (!empty($this->servicesInfo) &&
            isset($this->servicesInfo['saveTarsConfigFileDir']) &&
            isset($this->servicesInfo['saveTarsConfigFileName']))
        {
            TarsPlatform::loadTarsConfig($this->tarsConfig,
                $this->servicesInfo['saveTarsConfigFileDir'],
                $this->servicesInfo['saveTarsConfigFileName']);
        }

    }

    public function onManagerStart()
    {
        // rename manager process
        $this->_setProcessName($this->application . '.'
            . $this->serverName . ': manager process');
    }

    public function onWorkerStart($server, $workerId)
    {
        // tcp类型需要注册入口
        if ($this->servType === 'tcp') {
            $className = $this->servicesInfo['home-class'];
            self::$impl = new $className();
            $interface = new \ReflectionClass($this->servicesInfo['home-api']);
            $methods = $interface->getMethods();

            foreach ($methods as $method) {
                $docblock = $method->getDocComment();
                // 对于注释也应该有自己的定义和解析的方式
                self::$paramInfos[$method->name]
                    = $this->protocol->parseAnnotation($docblock);
            }
        }
        // websocket类型
        else if($this->servType === "websocket") {
            $this->namespaceName = $this->servicesInfo['namespaceName'];
            $this->executeClass = $this->servicesInfo['home-class'];
        }
        // 其他,包括http等类型
        else {
            $this->namespaceName = $this->servicesInfo['namespaceName'];
        }


        // task worker
        if ($workerId >= $this->worker_num) {
            $this->_setProcessName($this->application . '.'
                . $this->serverName . ': task worker process');

            // 将定时上报的任务投递到task worker 0,只需要投递一次
            $this->sw->task(
                [
                    'application' => $this->application,
                    'serverName' => $this->serverName,
                    'masterPid' => $server->master_pid,
                    'adapter' => $this->tarsServerConfig['adapters'][0]['adapterName'],
                    'client' => $this->tarsClientConfig
                ], 0);
        }
        else {
            $this->_setProcessName($this->application . '.'
                . $this->serverName . ': event worker process');

            // 定时timer执行逻辑
            if (isset($this->timers[$workerId])) {
                $runnable = $this->timers[$workerId];
                require_once $runnable;
                $className = $this->namespaceName . 'timer\\'
                    . basename($runnable, '.php');

                $obj = new $className();
                if (method_exists($obj, 'execute')) {
                    swoole_timer_tick($obj->interval, function () use ($workerId, $runnable, $obj) {
                        try {
                            $funcName = 'execute';
                            $obj->$funcName();
                        } catch (\Exception $e) {
                            error_log(__METHOD__ ." Error in runnable: $runnable, worker id: $workerId, e: " . print_r($e, true));
                        }
                    });
                }
            }
        }
    }


    public function onTask($server, $taskId, $fromId, $data)
    {
        switch ($taskId) {
            // 进行定时上报
            case 0: {
                $serverName = $data['serverName'];
                $application = $data['application'];

                \swoole_timer_tick(10000, function () use ($data, $serverName, $application) {

                    //获取当前存活的worker数目
                    $processName = $application.'.'.$serverName;
                    $cmd = "ps wwaux | grep '" . $processName . "' | grep 'event worker process' | grep -v grep  | awk '{ print $2}'";
                    exec($cmd, $ret);
                    $workerNum = count($ret);

                    if($workerNum >= 1){
                        TarsPlatform::keepaliveReport($data);
                    }
                    //worker全挂，不上报存活 等tars重启
                    else {
                        error_log(__METHOD__ . " All workers are not alive any more.");
                    }
                });

                //主调定时上报
                $locator = $data['client']['locator'];
                $socketMode = Consts::SWOOLE_SYNC_MODE;
                $statServantName = $data['client']['stat'];
                $reportInterval = $data['client']['report-interval'];

                \swoole_timer_tick($reportInterval,
                    function () use ($locator, $socketMode, $statServantName, $serverName, $reportInterval) {
                        try {
                            $statF = App::getStatF();
                            $statF->sendStat();
                        } catch (\Exception $e) {
                            error_log((string)$e);
                        }
                    });

                // 基础特性上报
                \swoole_timer_tick($reportInterval,
                    function () use ($locator, $application, $serverName) {
                        try {
                            TarsPlatform::basePropertyMonitor($locator, $application, $serverName);
                        } catch (\Exception $exception) {
                            error_log((string)$exception);
                        }
                });
                break;
            }
            default: break;
        }
    }


    // 这里应该找到对应的解码协议类型,执行解码,并在收到逻辑处理回复后,进行编码和发送数据
    public function onReceive($server, $fd, $fromId, $data)
    {
        $request = new Request();
        $request->reqBuf = $data;
        $request->paramInfos = self::$paramInfos;
        $request->impl = self::$impl;

        // 把全局对象带入到请求中,在多个worker之间共享
        $request->server = $this->sw;
        $request->setGlobal();

        $response = new Response();
        $response->fd = $fd;
        $response->fromFd = $fromId;
        $response->server = $server;


        // 处理管理端口的特殊逻辑
        $unpackResult = \TUPAPI::decodeReqPacket($data);
        $sServantName = $unpackResult['sServantName'];
        $sFuncName = $unpackResult['sFuncName'];

        // 处理管理端口相关的逻辑
        if ($sServantName === 'AdminObj') {
            TarsPlatform::processAdmin($this->tarsConfig, $unpackResult, $sFuncName, $response, $this->sw->master_pid);
        }

        $event = new Event();
        $event->setProtocol(ProtocolFactory::getProtocol($this->protocolName));
        $event->setBasePath($this->tarsServerConfig['basepath']);
        $event->setTarsConfig($this->tarsConfig);

        // 预先对impl和paramInfos进行处理,这样可以速度更快
        $event->onReceive($request, $response);

        $request->unsetGlobal();
    }

    /**
     * @param $request
     * @param $response
     * 针对http请求的响应
     */
    public function onRequest($request, $response)
    {
        $req = new Request();
        $req->data = get_object_vars($request);
        if (isset($req->data['zcookie'])) {
            $req->data['cookie'] = $req->data['zcookie'];
            unset($req->data['zcookie']);
        }
        if (empty($req->data['post'])) {
            $req->data['post'] = $request->rawContent();
        }
        $req->servType = $this->servType;
        $req->namespaceName = $this->namespaceName;

        $req->server = $this->sw;
        $req->setGlobal();

        $resp = new Response();
        $resp->servType = $this->servType;
        $resp->resource = $response;


        $event = new Event();
        $event->setProtocol(ProtocolFactory::getProtocol($this->protocolName));
        $event->onRequest($req, $resp);

        $req->unsetGlobal();
    }

    /**
     * @param $server
     * @param $frame
     * 增加websocket的回调
     */
    public function onMessage($server, $frame)
    {
        $className = $this->executeClass;

        $class = new $className();
        $fun = "onMessage";
        $class->$fun($server, $frame);
    }

    /**
     * @param $name
     * 设置启动的进程的名称
     */
    private function _setProcessName($name)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } elseif (function_exists('swoole_set_process_name')) {
            swoole_set_process_name($name);
        } else {
            error_log(__METHOD__ . ' failed. require cli_set_process_title or swoole_set_process_name.');
        }
    }
}
