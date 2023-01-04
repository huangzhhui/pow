<?php

declare(strict_types=1);

namespace App\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Engine\Channel;
use Hyperf\Logger\Logger;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class PowCommand extends HyperfCommand
{

    protected StdoutLoggerInterface $logger;

    protected string $cookieOscId = '';

    protected Channel $channel;

    // 累计增加热度
    protected int $totalIntegral = 0;

    // 启动时间
    protected int $startTime = 0;

    protected int $batch = 10;

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('pow');
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->channel = new Channel(0);
        $this->startTime = time();
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('OSChina 2023 POW Active Pow Command');
        // 设置参数 项目ID 用户ID Cookies
        $this->addArgument('oscId', InputArgument::REQUIRED, 'OSC ID');
        $this->addArgument('cookieOscId', InputArgument::REQUIRED, 'Cookie OSC ID');
        $this->addArgument('projectId', InputArgument::OPTIONAL, 'Project ID', '49127');
        $this->addOption('batch', 'b', InputArgument::OPTIONAL, 'Batch', 10);
    }

    public function handle()
    {
        $cookieOscId = $this->input->getArgument('cookieOscId');
        $this->cookieOscId = str_replace(' ', '%', $cookieOscId);
        $oscId = $this->input->getArgument('oscId');
        $projectId = $this->input->getArgument('projectId');
        $this->batch = (int)$this->input->getOption('batch');
        $this->logger->info(sprintf('开始运行 PoW 程序 Batch: %d project id: %s user id: %s cookieOscId: %s', $this->batch, $projectId, $oscId, $this->cookieOscId));
        $this->createSendPowChannel();
        $this->find($projectId, $oscId);
    }

    public function find($projectId, $oscId)
    {
        while (true) {
            $token = $this->randStr();
            for ($i = 0; $i < 999999; $i++) {
                $genKey = $projectId . ':' . $oscId . ':' . $i . ':' . $token;
                $hash = sha1($genKey);
                if (str_starts_with($hash, '00000') || str_contains($hash, 'oschina')) {
                    $this->channel->push([
                        'token' => $token,
                        'counter' => $i,
                        'user' => $oscId,
                        'project' => $projectId,
                    ]);
                    break;
                }
            }
            sleep(0);
        }
    }

    // 随机生成8位字符串
    private function randStr()
    {
        $str = '';
        for ($i = 0; $i < 8; $i++) {
            $str .= chr(mt_rand(97, 122));
        }
        return $str;
    }

    protected function createSendPowChannel() {
        co(function () {
            $payloads = [];
            while (true) {
                try {
                    $payload = $this->channel->pop();
                    $payloads[] = $payload;
                    if (count($payloads) >= $this->batch) {
                        $sendPayloads = $payloads;
                        $payloads = [];
                        $this->send($sendPayloads);
                    }
                } catch (\Exception) {

                }
            }
        });
    }

    protected function send(array $payloads)
    {
        $client = new Client();
        $jar = new CookieJar();
        $jar->setCookie(new SetCookie([
            'Name' => 'oscid',
            'Value' => $this->cookieOscId,
            'Domain' => 'www.oschina.net',
            'Path' => '/',
            'Expires' => time() + 86400 * 365,
        ]));
        $response = $client->post('https://www.oschina.net/action/api/pow', [
            'json' => $payloads,
            'verify' => false,
            'cookies' => $jar
        ]);
        $result = json_decode($response->getBody()->getContents(), true);
        if (is_array($result) && isset($result['msg'], $result['data']) && $result['msg'] === 'success') {
            $totalIntegral = 0;
            foreach ($result['data'] ?? [] as $item) {
                $totalIntegral += $item['integral'];
                $this->totalIntegral += $item['integral'];
            }
            $currentTime = date('Y-m-d H:i:s');
            $runTime = time() - $this->startTime;
            $this->logger->info(sprintf( '%s 成功增加热度：%d，累计增加热度：%d 运行时长: %s 速率: %d热度/秒', $currentTime, $totalIntegral, $this->totalIntegral, $this->humanTime($runTime), $this->totalIntegral / $runTime));
        } else {
            $this->logger->error(sprintf('增加热度失败, 原因: %s', $result['msg'] ?? '未知'));
        }
    }

    protected function humanTime(int $time): string
    {
        $units = [
            '年' => 31536000,
            '个月' => 2592000,
            '星期' => 604800,
            '天' => 86400,
            '小时' => 3600,
            '分钟' => 60,
            '秒' => 1,
        ];
        $str = '';
        foreach ($units as $unit => $value) {
            if ($time >= $value) {
                $num = floor($time / $value);
                $time = $time % $value;
                $str .= $num . $unit;
            }
        }
        return $str;
    }
}
