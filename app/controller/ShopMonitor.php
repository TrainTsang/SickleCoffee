<?php

namespace app\controller;


use app\service\MonitorService;

ini_set('memory_limit', '1024M');
set_time_limit(0);

/**
 * 店铺监控
 * 1、监控门店营业状况
 * 2、监控门店入群二维码变化
 * 3、维护城市ID与城市名称映射表
 * Class ShopMonitor
 * @package app\controller
 */
class ShopMonitor
{
    public function test()
    {
        $class = new MonitorService();
        $res = $class->refreshCity();
        $cityIds = [];
        foreach ($res['data']['cities_get'] as $cityInfo) {
            $cityIds[] = $cityInfo['cityId'];
        }
        var_dump($cityIds);
        $res = $class->refreshDepart($cityIds);
        var_dump($res);
    }
}