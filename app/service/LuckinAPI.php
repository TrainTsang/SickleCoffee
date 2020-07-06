<?php

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * 瑞幸接口实现
 * - 只负责接口抓取是否成功，数据有效性由调用方负责检查
 * Class LuckinAPI
 * @package app\service
 */
class LuckinAPI
{
    const ERR_CODE_REQUEST_FAILED = 40001;
    const ERR_CODE_SET_COOKIE_FAILED = 40011;
    const DEFAULT_OPTION = [
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.116 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br'
        ]
    ];
    private $client;
    private $option = self::DEFAULT_OPTION;
    private $cookie;
    private $lastRequest;

    public function __construct($timeout = 10)
    {
        $this->client = new Client(['timeout' => $timeout]);
    }

    /**
     * 获取门店地址
     * @param int $cityId
     * @param string $searchValue
     * @param int $offset
     * @param int $pageSize
     * @return array
     */
    public function getDepartList($cityId = 1, $searchValue = '', $offset = 0, $pageSize = 10):array
    {
        $param = [
            'cityId' => $cityId,
            'searchValue' => $searchValue,
            'offset' => $offset,
            'pageSize' => $pageSize
        ];
        $query['params'] = json_encode($param, JSON_UNESCAPED_UNICODE);
        $url = API_GET_DEPART_LIST . '?' . http_build_query($query);

        //如果cookie已经存在，使用cookie
        if (!empty($this->cookie)) {
            $this->option['headers']['Cookie'] = $this->cookie;
        }

        try {
            $this->lastRequest = $this->client->get($url, $this->option);
            if (empty($this->cookie)) {
                if (!$this->setCookie()) {
                    return func_code_return(self::ERR_CODE_SET_COOKIE_FAILED, '请求门店列表时，Cookie设置失败');
                }
            }
            return func_code_return(
                ERR_CODE_SUCCESS,
                '',
                ['content' => $this->lastRequest->getBody()->getContents()]
            );
        } catch (RequestException $e) {
            return func_code_return(self::ERR_CODE_REQUEST_FAILED, '请求门店列表数据异常', ['error' => $e]);
        }
    }

    /**
     * 获取微信二维码
     * @param $deptId
     * @return array
     */
    public function getQr($deptId)
    {
        $param = [
            'deptId' => $deptId
        ];
        $query['params'] = json_encode($param, JSON_UNESCAPED_UNICODE);
        $url = API_GET_WX_GROUP_CODE . '?' . http_build_query($query);
        //如果cookie已经存在，使用cookie
        if (!empty($this->cookie)) {
            $this->option['headers']['Cookie'] = $this->cookie;
        }
        try {
            $this->lastRequest = $this->client->get($url, $this->option);
            if (empty($this->cookie)) {
                if (!$this->setCookie()) {
                    return func_code_return(self::ERR_CODE_SET_COOKIE_FAILED, '请求门店入群码时，Cookie设置失败');
                }
            }
            return func_code_return(
                ERR_CODE_SUCCESS,
                '',
                ['content' => $this->lastRequest->getBody()->getContents()]
            );
        } catch (RequestException $e) {
            return func_code_return(self::ERR_CODE_REQUEST_FAILED, '请求门店入群码数据异常', ['error' => $e]);
        }
    }

    /**
     * 获取城市
     */
    public function getCityAll()
    {
        $url = API_GET_CITY_LIST;
        try {
            $request = $this->client->get($url);
            return func_code_return(
                ERR_CODE_SUCCESS,
                '',
                ['content' => $request->getBody()->getContents()]
            );
        } catch (RequestException $e) {
            return func_code_return(self::ERR_CODE_REQUEST_FAILED, '请求城市列表数据异常', ['error' => $e]);
        }
    }


    /**
     * 设置cookie
     */
    public function setCookie()
    {
        $setCookieArr = $this->lastRequest->getHeader('set-cookie');

        if (count($setCookieArr) > 2) { //成功拿到cookie
            $cookieArr = [];
            foreach ($setCookieArr as $item) {
                $cookieArr[] = explode(';', $item)[0];
            }
            $this->cookie = implode(';', $cookieArr);

            func_shell_echo('【cookie】' . $this->cookie);
            return true;
        } else { //未能拿到cookie
            var_dump($setCookieArr);
            $this->resetClient();
            return false;
        }
    }

    /**
     * 重置当前client（新的client，此前的cookie、option都会重置）
     */
    public function resetClient()
    {
        $this->cookie = null;
        $this->lastRequest = null;
        $this->option = self::DEFAULT_OPTION;
        $this->client = new Client();
        return;
    }

}
