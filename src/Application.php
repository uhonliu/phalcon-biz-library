<?php

namespace Codeages\PhalconBiz;

use Codeages\Biz\Framework\Context\Biz;
use Phalcon\DiInterface;
use Phalcon\Di;
use Phalcon\Http\RequestInterface;
use Phalcon\Http\ResponseInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Codeages\PhalconBiz\Event\GetResponseEvent;
use Codeages\PhalconBiz\Event\FinishRequestEvent;
use Codeages\PhalconBiz\Event\FilterResponseEvent;
use Codeages\PhalconBiz\Event\GetResponseForExceptionEvent;
use Codeages\PhalconBiz\Event\GetResponseForControllerResultEvent;
use Codeages\PhalconBiz\Event\WebEvents;
use Codeages\Biz\Framework\Context\BizAwareInterface;

class Application
{
    /**
     * @var DiInterface
     */
    protected $di;

    /**
     * @var Biz
     */
    protected $biz;

    protected $debug = false;

    protected $config;

    public function __construct(Biz $biz, $config = [])
    {
        $this->biz = $biz;
        $this->config = $config;
        $this->debug = isset($biz['debug']) ? $biz['debug'] : false;
        $this->di = $this->initializeContainer();
        $this->di['biz'] = $biz;
    }

    /**
     * Get Phalcon Application DI
     *
     * @return DiInterface
     */
    public function getDI()
    {
        return $this->di;
    }

    public function isDebug()
    {
        return $this->debug;
    }

    protected function initializeContainer()
    {
        $di = new Di();
        $config = $this->config;
        $biz = $this->biz;

        $di->setShared('annotations', function () use ($biz) {
            if ($biz['debug']) {
                return new \Phalcon\Annotations\Adapter\Memory();
            }

            return new \Phalcon\Annotations\Adapter\Files([
                'annotationsDir' => rtrim($biz['cache_directory'], "\/\\").DIRECTORY_SEPARATOR,
            ]);
        });

        $di->setShared('mvc_dispatcher', function () {
            return new \Phalcon\Mvc\Dispatcher();
        });

        $di->setShared('filter', function () {
            return new \Phalcon\Filter();
        });

        $di->setShared('router', function () {
            // Use the annotations router. We're passing false as we don't want the router to add its default patterns
            $router = new \Phalcon\Mvc\Router\Annotations(false);
            $router->setControllerSuffix('');
            $router->setActionSuffix('');

            return $router;
        });

        $di->setShared('request', function () {
            return new \Phalcon\Http\Request();
        });

        $di->set('response', function () {
            return new \Phalcon\Http\Response();
        });

        $subscribers = $config['subscribers'] ?? [];
        $di->setShared('event_dispatcher', function () use ($subscribers) {
            $dispatcher = new EventDispatcher();

            foreach ($subscribers as $subscriber) {
                $dispatcher->addSubscriber(new $subscriber());
            }

            return $dispatcher;
        });

        if (isset($config['user_provider'])) {
            $di->setShared('user_provider', function () use ($config, $biz) {
                $provider = new $config['user_provider']();
                if ($provider instanceof BizAwareInterface) {
                    $provider->setBiz($biz);
                }

                return $provider;
            });
        }

        return $di;
    }

    public function handle()
    {
        $request = $this->di['request'];

        if (('GET' !== $request->getMethod()) && 0 === strpos($request->getHeader('Content-Type'), 'application/json')) {
            $data = $request->getJsonRawBody(true) ?: array();
            foreach ($data as $key => $value) {
                $_POST[$key] = $value;
            }
        }

        try {
            $response = $this->doHandle();
        } catch (\Exception $e) {
            $response = $this->handleException($e, $request);
        }

        $response->send();
    }

    private function handleException(\Exception $e, $request)
    {
        $event = new GetResponseForExceptionEvent($this, $request, $e);
        $this->di['event_dispatcher']->dispatch(WebEvents::EXCEPTION, $event);

        // Listener 中可能会重设 Exception，所以这里重新获取了 Exception。
        $e = $event->getException();

        if (!$event->hasResponse()) {
            $this->finishRequest($request);
            throw $e;
        }

        $response = $event->getResponse();

        try {
            return $this->filterResponse($response, $request);
        } catch (\Exception $e) {
            return $response;
        }
    }

    public function doHandle()
    {
        $request = $this->di['request'];
        $event = new GetResponseEvent($this, $request);
        $this->di['event_dispatcher']->dispatch(WebEvents::REQUEST, $event);

        if ($event->hasResponse()) {
            return $this->filterResponse($event->getResponse(), $request);
        }

        $router = $this->di['router'];

        $discovery = new AnnotationRouteDiscovery($router, $this->di['annotations'], $this->biz['cache_directory'], $this->debug);
        if (empty($this->config['route_discovery']) || !is_array($this->config['route_discovery'])) {
            throw new \RuntimeException("`route_discovery`未配置或配置不正确。");
        }

        foreach ($this->config['route_discovery'] as $namespace => $directory) {
            $discovery->discover($namespace, $directory);
        }

        $router->handle();

        if (!$router->getMatchedRoute()) {
            throw new NotFoundException("URI {$request->getURI()} is not found.");
        }

        $dispatcher = $this->di['mvc_dispatcher'];

        $dispatcher->setControllerSuffix('');
        $dispatcher->setActionSuffix('');

        $dispatcher->setNamespaceName($router->getNamespaceName());
        $dispatcher->setControllerName($router->getControllerName());

        $dispatcher->setActionName(
            $router->getActionName()
        );

        $dispatcher->setParams(
            $router->getParams()
        );

        $dispatcher->dispatch();
        $response = $dispatcher->getReturnedValue();

        // view
        if (!$response instanceof ResponseInterface) {
            $event = new GetResponseForControllerResultEvent($this, $request, $response);
            $this->di['event_dispatcher']->dispatch(WebEvents::VIEW, $event);

            if ($event->hasResponse()) {
                $response = $event->getResponse();
            }

            if (!$response instanceof ResponseInterface) {
                $msg = 'The controller must return a response.';
                if (null === $response) {
                    $msg .= ' Did you forget to add a return statement somewhere in your controller?';
                }
                throw new \LogicException($msg);
            }
        }

        return $response;
    }

    /**
     * 过滤 Response
     *
     * @param ResponseInterface $response
     * @param RequestInterface  $request
     *
     * @return Response 过滤后的 Response 实例
     */
    private function filterResponse(ResponseInterface $response, RequestInterface $request)
    {
        $event = new FilterResponseEvent($this, $request, $response);

        $this->di['event_dispatcher']->dispatch(WebEvents::RESPONSE, $event);

        $this->finishRequest($request);

        return $event->getResponse();
    }

    /**
     * 派发完成请求的事件
     *
     * @param RequestInterface $request
     */
    private function finishRequest(RequestInterface $request)
    {
        $this->di['event_dispatcher']->dispatch(WebEvents::FINISH_REQUEST, new FinishRequestEvent($this, $request));
    }
}
