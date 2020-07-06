<?php


namespace app\service;

use lib\LogHelper;

/**
 * 瑞幸数据监控与清洗服务
 * Class MonitorService
 * @package app\service
 */
class MonitorService
{
    private $luckinAPI;

    public function __construct()
    {
        $this->luckinAPI = new LuckinAPI();
    }

    /**
     * 城市列表更新
     */
    public function refreshCity(): array
    {
        /*
         * 原则上只增加城市，不能修改ID和城市之间的关系
         * 如果真的出现不一致的情况，应当写入日志，人工干预
         */
        $time = time();
        $retryTimes = 0;
        $res = $this->luckinAPI->getCityAll();

        //如果失败三次重试
        while ($res['code'] !== ERR_CODE_SUCCESS && $retryTimes < 3) {
            $retryTimes++;
            $res = $this->luckinAPI->getCityAll();
        }
        if ($res['code'] !== ERR_CODE_SUCCESS) {
            //错误日志
            LogHelper::fastFileLog(__CLASS__, __FUNCTION__, $res['msg'], $res['data']['error']);
            die($res['msg']);
        }
        //读取到数据
        $data = json_decode($res['data']['content'], true);
        unset($res);
        if ($data['status'] !== 'SUCCESS') {
            //错误日志
            $err = '接口未能正确返回STATUS）';
            LogHelper::fastFileLog(__CLASS__, __FUNCTION__, $err, '', $data);
            die($err);
        } else if (empty($data['content'])) {
            //错误日志
            $err = '接口返回的城市为空';
            LogHelper::fastFileLog(__CLASS__, __FUNCTION__, $err, '', $data);
            die($err);
        }

        //运行日志
        LogHelper::fastFileLog(__CLASS__, __FUNCTION__,
            '完成城市列表获取', '', $data['content'], '', null,
            '/data/log/run/',
            date('Y-m-d H:i:s', $time) . ' City Update.txt',
            false
        );
        //TODO 数据写入数据库

        $return = [];
        foreach ($data['content'] as $city) {
            $thisData = [
                'cityId' => $city['cityId'],
                'name' => $city['name'],
                'showName' => $city['showName'],
            ];
            $return['cities_get'][] = $thisData;
        }


        //TODO 返回当前数据库的全部城市列表、本次新增的城市、本次删除的城市等信息
        return func_code_return(ERR_CODE_SUCCESS, '', $return);

    }

    /**
     * 店铺运营情况更新
     * @param array $cityIds
     * @return array
     */
    public function refreshDepart(array $cityIds): array
    {
        if (empty($cityIds)) {
            return func_code_return(ERR_CODE_CUSTOMIZE, '城市IDs为空');
        } else if (!is_array($cityIds)) {
            return func_code_return(ERR_CODE_CUSTOMIZE, '城市必须以数组形式传入');
        }

        LogHelper::createDir(ROOT_PATH . '/data/log/run/');
        $file = ROOT_PATH . '/data/log/run/' . date('Y-m-d H:i:s') . ' Depart Update.txt';
        $offset = 0;
        $pageSize = 10;//固定为10，瑞幸这个pageSize好像并没有用
        $i = 0;
        while ($i < count($cityIds)) {
            //FIXME 【低】反爬，如果没有代理，就要休息一会儿
            usleep(500010);
            func_shell_echo("城市：{$cityIds[$i]}；轮次：{$offset}");
            $res = $this->luckinAPI->getDepartList($cityIds[$i], '', $offset, $pageSize);
            if (!self::checkLuckinResult($res)) {
                //重试当前请求
                continue;
            }
            $jsonContent = json_decode($res['data']['content'], true);
            if (!$jsonContent) {
                //重试当前请求
                func_shell_echo('解析故障，怀疑Cookie失效，休息20秒后，调用重置Cookie');
                sleep(20);
                $this->luckinAPI->resetClient();
                continue;
            }
            if (!empty($jsonContent['content'])) {
                //存入日志
                //FIXME 【中】判断请求的内容是否是success，否则需要重试
                @file_put_contents($file, $res['data']['content'] . "\n______________分割______________\n", FILE_APPEND);
                //TODO 存入数据库
                $offset += $pageSize;
            } else {
                $offset = 0;
                $i++;
            }
        }
        return func_code_return(ERR_CODE_SUCCESS);
    }

    /**
     * 二维码变更监控
     * @param array $departList 格式：[{"cityId":城市id，"departId":门店ID}, ……]
     * @return array
     */
    public function refreshQR(array $departList): array
    {
        if (empty($departList)) {
            return func_code_return(ERR_CODE_CUSTOMIZE, '门店IDs为空');
        } else if (!is_array($departList)) {
            return func_code_return(ERR_CODE_CUSTOMIZE, '门店必须以数组形式传入');
        } else if (!isset($departList[0]['cityId']) || !isset($departList[0]['departId'])) {
            return func_code_return(ERR_CODE_CUSTOMIZE, '必须为城市ID+门店ID的格式');
        }
        $i = 0;

        while ($i < count($departList)) {
            if (empty($departList[$i]['cityId']) || empty($departList[$i]['departId'])) {
                //缺失，重试也没用，打日之后继续执行后续的请求
                LogHelper::fastFileLog(__CLASS__, __FUNCTION__, '城市ID或门店ID缺失', '', $departList[$i]);
                $i++;
                continue;
            }
            //检查是否存在海报
            $qrRes = $this->luckinAPI->getQr($departList[$i]['departId']);
            //检查结果是否正常
            if (!self::checkLuckinResult($qrRes)) {
                //重试当前请求
                continue;
            }
            //存在二维码，下载
            $jsonContent = json_decode($qrRes['data']['content'], true);
            if (!$jsonContent) {
                //重试当前请求
                func_shell_echo('解析故障，怀疑Cookie失效，休息20秒后，调用重置Cookie');
                sleep(20);
                $this->luckinAPI->resetClient();
                continue;
            }
            $qrContent = $jsonContent['content'];
            if (!empty($qrContent['deptGroupCode'])) {
                /*
                 * 下载图片，计算图片二维码的值
                 * 一会儿需要比对是否已经存在，链接、二维码任意变化一个
                 * 都认为是一个新的（还没摸清规律，先这么测试）
                 */
                $qrRaw = file_get_contents($qrContent['deptGroupCode']);
                if (empty($qrRaw)) {
                    //重试当前请求
                    func_shell_echo('文件下载失败', '', 'red');
                    continue;
                }
                $qrReader = new \Zxing\QrReader($qrRaw, \Zxing\QrReader::SOURCE_TYPE_BLOB);
                $url = $qrReader->text(); //返回二维码的内容
                func_shell_echo('二维码内容：' . $url);
                if (strpos($url, 'weixin') !== false) {
                    $urlType = 'W';
                } else if (strpos($url, 'url.cn') !== false) {
                    $urlType = 'U';
                } else {
                    $urlType = 'N';
                }
                //FIXME 【中】暂时还是先直接存文件，没用去检查活码等操作
                $filePath = ROOT_PATH . '/data/qr_code/raw_all/';
                $filePathNew = ROOT_PATH . '/data/qr_code/raw_new/';
                LogHelper::createDir($filePath);
                LogHelper::createDir($filePathNew);
                $fileName = $filePath . $departList[$i]['cityId'] . '-' . $departList[$i]['departId'] . '-' . $urlType . '-' . md5($url) . '.jpg';
                $fileNameNew = $filePathNew . $departList[$i]['cityId'] . '-' . $departList[$i]['departId'] . '-' . $urlType . '-' . md5($url) . '.jpg';
                unset($qrReader);
                unset($url);
                if (is_file($fileName)) {
                    func_shell_echo('跳过' . $departList[$i]['departId']);
                } else {
                    func_shell_echo('保存' . $departList[$i]['departId']);
                    func_shell_echo("新文件：" . $fileNameNew, 'red');
                    $save = @file_put_contents($fileName, $qrRaw);
                    if (!$save) {
                        func_shell_echo('保存文件失败：' . $fileName, 'yellow');
                    }
                    $save = @file_put_contents($fileNameNew, $qrRaw);
                    if (!$save) {
                        func_shell_echo('保存文件失败：' . $fileName, 'yellow');
                    }
                }
            }
            $i++;
        }
        return func_code_return(ERR_CODE_SUCCESS);
    }

    /**
     * 店铺开关统计
     */
    public function checkDepartStatus()
    {

    }

    /**
     * 检查瑞幸的接口是否成功
     * FIXME 【低】在循环调用时，暂时没有设置上限次数，特殊情况下会死循环，但是一般没问题
     * @param array $res
     * @return bool
     */
    private static function checkLuckinResult(array $res): bool
    {
        switch ($res['code']) {
            case LuckinAPI::ERR_CODE_SET_COOKIE_FAILED:
                func_shell_echo($res['msg'] . '|休息20秒后重试');
                sleep(20);
                return false;
            case LuckinAPI::ERR_CODE_REQUEST_FAILED:
                func_shell_echo($res['msg'] . '|休息5秒后重试');
                sleep(5);
                return false;
            case ERR_CODE_CUSTOMIZE:
                func_shell_echo($res['msg']);
                return false;
        }
        return true;
    }
}