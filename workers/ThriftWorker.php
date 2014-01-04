<?php
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';

define('THRIFT_ROOT', realpath(__DIR__. '/../applications/Thrift'));
require_once THRIFT_ROOT . '/Lib/Thrift/ClassLoader/ThriftClassLoader.php';

use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', THRIFT_ROOT.'/Lib');
$loader->registerNamespace('Service', THRIFT_ROOT);
$loader->register();

/**
 * 
 *  ThriftWorker
 * 
 * @author walkor <worker-man@qq.com>
 */
class ThriftWorker extends Man\Core\SocketWorker
{
    /**
     * Thrift processor
     * @var object 
     */
    protected $processor = null;
    
    /**
     * 使用的协议,默认TBinaryProtocol,可以在配置中更改
     * @var string
     */
    protected $thriftProtocol = "\\Thrift\\Protocol\\TBinaryProtocol";
    
    /**
     * 使用的传输类,默认是TBufferedTransport,可以在配置中更改
     * @var string
     */
    protected $thriftTransport = "\\Thrift\\Transport\\TBufferedTransport";
    
    /**
     * 进程启动时做的一些初始化工作
     * @see Man\Core.SocketWorker::onStart()
     * @return void
     */
    public function onStart()
    {
        // 查看配置中是否有设置Protocol
         if($protocol = \Man\Core\Lib\Config::get($this->workerName.'.thrift_protocol'))
        {
            $this->thriftProtocol = "\\Thrift\\Protocol\\" . $protocol;
            if(!class_exists($this->thriftProtocol, true))
            {
                $this->notice('Class ' . $this->thriftProtocol . ' not exsits , Please check ./conf/conf.d/'.$this->workerName.'.conf');
                return;
            }
        }
        // 查看配置中是否有设置Transport
        if($transport = \Man\Core\Lib\Config::get($this->workerName.'.thrift_transport'))
        {
            $this->thriftTransport = "\\Thrift\\Transport\\".$transport;
            if(!class_exists($this->thriftTransport, true))
            {
                $this->notice('Class ' . $this->thriftTransport . ' not exsits , Please check ./conf/conf.d/'.$this->workerName.'.conf');
                return;
            }
        } 
        
        //检查 processor 文件是否存在
        $processor_file = THRIFT_ROOT . '/Services/'.$this->workerName.'/'.$this->workerName.'.php';
        $processor_class_name = "\\Services\\".$this->workerName."\\".$this->workerName.'Processor';
        if(!is_file($processor_file))
        {
            $this->notice("File $processor_file not exsits and can't find class $processor_class_name" );
            return;
        }
        require_once $processor_file;
        
        // 检查类是否存在
        if(!class_exists($processor_class_name))
        {
            $this->notice("Class $processor_class_name not found" );
            return;
        }
        
        //检查 handler 文件是否存在
        $handler_file = THRIFT_ROOT . '/Services/'.$this->workerName.'/'.$this->workerName.'Handler.php';
        $handler_class_name ="\\Services\\".$this->workerName."\\".$this->workerName.'Handler';
        if(!is_file($handler_file))
        {
            $this->notice("Class $handler_class_name not found" );
            return;
        }
        require_once $handler_file;
        
        // 检查类是否存在
        if(!class_exists($handler_class_name))
        {
            $this->notice("File $handler_file not exsits and can't find class $handler_class_name" );
            return;
        }
        
        $handler = new $handler_class_name();
        $this->processor = new $processor_class_name($handler);
    }
    
    /**
     * 处理受到的数据
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return void
     */
    public function dealInputBase($connection, $flag, $fd = null)
    {
        $this->currentDealFd = (int)$connection;
        if(feof($connection))
        {
            return $this->closeClient($this->currentDealFd);
        }
        $socket = new Thrift\Transport\TSocket();
        $socket->setHandle($connection);
        $transport = new $this->thriftTransport($socket);
        $protocol = new $this->thriftProtocol($transport);
        
        // 执行处理
        try{
            // 业务处理
            $this->processor->process($protocol, $protocol);
        }
        catch(\Exception $e)
        {
            $this->notice('CODE:' . $e->getCode() . ' MESSAGE:' . $e->getMessage()."\n".$e->getTraceAsString()."\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".var_export($this->recvBuffers[$fd]['buf'],true)."]\n");
            $this->statusInfo['throw_exception'] ++;
            $this->sendToClient($e->getMessage());
        }
        
        // 是否是长连接
        if(!$this->isPersistentConnection)
        {
                $this->closeClient($fd);
        }

        // 检查是否是关闭状态或者是否到达请求上限
        if($this->workerStatus == self::STATUS_SHUTDOWN || $this->statusInfo['total_request'] >= $this->maxRequests)
        {
            // 停止服务
            $this->stop();
            // EXIT_WAIT_TIME秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }
    
    public function dealInput($recv_str)
    {
    }
    
   public function dealProcess($recv_str)
   {
   }
}