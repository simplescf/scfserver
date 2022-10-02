<?php
/*
 * @Author: your name
 * @Date: 2020-05-19 21:25:38
 * @LastEditTime: 2021-11-04 13:31:07
 * @LastEditors: Please set LastEditors
 * @Description: In User Settings Edit
 * @FilePath: /simplescf/src/SSTcbDB.php
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

use Exception;
use TencentCloudBase\TCB;

class SSTcbDB
{
    private $tcb;
    private $db;
    private $conf;
    //
    private $fields = [];
    //
    private $outFields = [];
    private $joinFields = [];

    private $whereState = [];
    private $limit;
    private $skip;
    private $order = [];
    private $_;
    private $relOrderField = []; //关联查询中需要排序的属性

    public function __construct()
    {
        $this->conf = new SSConf();
        $tcb = $this->conf->loadByKey("tcb");
        $qk = $this->conf->loadByKey("qcloudkey");
        $this->tcb = new Tcb([
            'secretId' => $qk["secid"],
            'secretKey' => $qk["seckey"],
            'env' => $tcb["envid"],
        ]);
        $this->db = $this->tcb->getDatabase();
        $this->_ = $this->db->command;
    }

    public function getDB()
    {
        return $this->db;
    }

    /**
     * 在表中插入一个对象
     * @param [object] $obj 插入的对象数据
     * @param [str] $table 表
     * @return {mix} false失败, 否则返回字符串的id
     */
    public function insertCollect($obj, $table)
    {
        $tmp = $this->db->collection($table)->add($obj);
        $this->cleanWhere();
        if (isset($tmp["id"])) {
            return $tmp['id'];
        }
        SSLog::error($table, $tmp);
        return false;
    }

    /**
     * 在表中插入一个对象
     * @param array $obj 插入的对象数据
     * @param string $beanName collect对应的配置文件中的名称
     * @return bool|string false失败, 否则返回字符串的id
     */
    public function addObject($obj, $beanName)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        $addObject = [];

        foreach ($bean['propertys'] as $key => $val) {
            if (array_key_exists($key, $obj)) {
                if ($val['type'] == 'point') {
                    $addObject[$key] = $this->getPoint($obj[$key]);
                } else {
                    $addObject[$key] = $obj[$key];
                }
            } else if ($val['required'] === true) {
                SSLog::error("{$beanName}缺失必填字段{$key}");
                return false;
            }
        }

        $tmp = $this->db->collection($bean['collection'])->add($addObject);
        $this->cleanWhere();
        if (isset($tmp["id"])) {
            return $tmp['id'];
        }
        SSLog::error($tmp);
        return false;
    }

    private function getPoint($obj)
    {
        $gps = [];
        if (isset($obj['lng'])) {
            $gps['lng'] = doubleVal($obj['lng']);
        } else if (isset($obj['longitude'])) {
            $gps['lng'] = doubleVal($obj['longitude']);
        }

        if (isset($obj['lat'])) {
            $gps['lat'] = doubleVal($obj['lat']);
        } else if (isset($obj['latitudes'])) {
            $gps['lat'] = doubleVal($obj['latitudes']);
        }

        return $this->db->Point($gps['lng'], $gps['lat']);
    }

    /**
     * @description: 批量添加
     * @param {*}
     * @return {Array[String|false]} 添加结果数组, 每条数据添加成功则为对应的_id值,否则为false
     */
    public function insertObjectBatch($objs, $table)
    {
        $ids = [];
        foreach ($objs as $obj) {
            $tmp = $this->db->collection($table)->add($obj);
            $id = false;
            if (isset($tmp["id"])) {
                $id = $tmp["id"];
            } else {
                SSLog::error($tmp);
            }
            array_push($ids, $id);
        }
        $this->cleanWhere();
        return $ids;
    }

    /**
     * @description: 根据条件查询匹配的数据条数
     * @param {*}
     * @return int 条数
     */
    public function count($beanName)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        $res = $this->db->collection($bean['collection'])->where($this->whereState)->count();
        $total = $res['total'];
        $this->cleanWhere();
        return $total;
    }

    /**
     * @description: 查询符合条件的单条数据
     * 适合确定条件只能查询出一条数据时用 , 如根据唯一索引查询
     * @param String $beanName 查询的对象名
     * @return array|bool 查询失败或无数据返回false, 否则返回对应的数组类型数据
     */
    public function getOneObject($beanName)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        $this->formatField($bean);
        $this->limit = 1;
        $this->skip = 0;
        $res = $this->getAs($bean);
        $this->cleanWhere();
        if ($res !== false) {
            if (sizeof($res) > 0) {
                return $this->formatProperty($bean, $res)[0];
            }
            return false;
        }
        return false;
    }

    /**
     * 删除数据
     * @return false|int 
     */
    public function delObject($beanName)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        $table = $bean['collection'];

        if (sizeof($this->whereState) == 0) {
            SSLog::error('未指定筛选条件');
            return false;
        }
        if (isset($this->whereState["_id"])) {
            $ls = $this->db->collection($table)->doc($this->whereState["_id"])->remove();
        } else {
            $ls = $this->db->collection($table)->where($this->whereState)->remove();
        }
        $this->cleanWhere();
        if (isset($ls['deleted'])) {
            return $ls['deleted'];
        }
        SSLog::error($ls);
        return false;
    }

    public function cleanWhere()
    {
        $this->limit = null;
        $this->skip = null;
        $this->fields = [];
        $this->outFields = [];
        $this->whereState = [];
        $this->joinFields = [];
        $this->relOrderField = [];
        $this->order = [];
    }

    /**
     * 设置返回的字段
     * 若要扣除某个属性不返回, 属性名前追加-,此功能和普通返回属性不兼容
     */
    public function field()
    {
        //outFields
        foreach (func_get_args() as $para) {
            if (preg_match('/^-(.*)/', $para, $match)) {
                $this->outFields[$match[1]] = true;
            } else {
                $this->fields[$para] = true;
            }
        }
        return $this;
    }
    

    /**
     * 查询列,合并属性列表,转换为查询格式
     */
    private function formatField($bean)
    {

        $tmps = [];
        if (sizeof($this->outFields) > 0) {
            foreach ($bean['propertys'] as $key => $val) {
                if (!array_key_exists($key, $this->outFields)) {
                    $tmps[$key] = true;
                }
            }
        } else {
            if (sizeof($this->fields) > 0) {
                $tmps = $this->fields;
            } else {
                foreach ($bean['propertys'] as $key => $val) {
                    $tmps[$key] = true;
                }
            }
        }

        //关联键在原表中不存在

        foreach ($tmps as $key => $v) {

            if ($bean['propertys'][$key]['type'] == 'one-to-many' ||
                $bean['propertys'][$key]['type'] == 'one-to-one') {
                array_push($this->joinFields, $key);
                unset($tmps[$key]);
            }
        }

        $this->fields = $tmps;
    }

    /**
     * @description: 查询符合条件的所有数据
     * @param {array} 查询条件,排序: orderby=>[key, asc/desc]
     * @return {mix} false 返回失败,用===判断false,已避免空数据的混淆判断, array 返回数组
     */
    public function getArrays($table)
    {
        $coll = $this->db->collection($table);

        if (sizeof($this->fields) > 0) {
            $coll = $coll->field($this->fields);
        }

        $data = $coll->where($this->whereState)->get();
        $this->cleanWhere();
        if (isset($data['data'])) {
            return $data['data'];
        }
        SSLog::error($data);
        return false;
    }

    public function limit($skip, $limit)
    {
        $this->skip = $skip;
        $this->limit = $limit;
        return $this;
    }

    private function getAs($bean)
    {
        $data = [];
        $coll = $this->db->collection($bean['collection']);

        //要查询的属性名
        if (sizeof($this->fields) > 0) {
            $coll = $coll->field($this->fields);
        }
        if ($this->limit != null) {
            $coll = $coll->skip($this->skip)->limit($this->limit);
        }
        if (sizeof($this->order) >= 2) {
            $coll = $coll->orderBy($this->order['property'], $this->order['order']);
        }

        //关联属性查询,需要排序的依次查询,一次只支持一个
        if (sizeof($this->relOrderField) > 0) {
            $tmpconf = $this->whereState;
            foreach ($this->relOrderField as $key => $vals) {
                //详细查询前,先查询否有数据,以提高性能
                $countConf = [];
                foreach ($this->whereState as $ck => $cv) {
                    //Point格式不支持count条数查询
                    if ($bean['propertys'][$ck]['type'] == 'point') {
                        continue;
                    }
                    $countConf[$ck] = $cv;
                }
                $countConf[$key] = $this->db->command->in($vals);
                $excount = $coll->where($countConf)->count()['total'];
                if ($excount == 0) {
                    continue;
                }

                foreach ($vals as $val) {
                    $tmpconf[$key] = $val;
                    $limit = $this->limit - sizeof($data);
                    if ($limit <= 0) {
                        break;
                    }
                    $res = $coll->where($tmpconf)->limit($limit)->get();
                    if (isset($res['data'])) {
                        $data = array_merge($data, $res['data']);
                    } else {
                        SSLog::error('集合查询异常', $data);
                        return false;
                    }

                }
            }
        } else {
            $data = $coll->where($this->whereState)->get();
            if (isset($data['data'])) {
                $data = $data['data'];
            } else {
                SSLog::error("集合{$bean['collection']}查询异常", $this->whereState, $data);
                $data = [];
                return false;
            }
        }

        if (sizeof($data) == 0) {
            return $data;
        }
        return $this->mergerRelaObject($bean, $data);
    }

    /**
     * 查询关联属性的值
     */
    private function mergerRelaObject($bean, $ds)
    {
        if (sizeof($this->joinFields) == 0) {
            return $ds;
        }
        foreach ($this->joinFields as $key) {
            $relType = $bean['propertys'][$key]['type'];
            $tmps = [];
            $fromKey = $bean['propertys'][$key]['relation']['fromKey'];
            $toKey = $bean['propertys'][$key]['relation']['toKey'];

            $order = [];
            if (isset($bean['propertys'][$key]['relation']['queryOrder'])) {
                $order = $bean['propertys'][$key]['relation']['queryOrder'];
            }

            foreach ($ds as $d) {
                if (isset($d[$fromKey])) {
                    array_push($tmps, $d[$fromKey]);
                }
                //  else {
                //     SSLog::error($bean['collection'].' '.$fromKey." 关联异常", $d['_id']);
                // }
            }

            $res = $this->query(
                $bean['propertys'][$key]['relation']['collection'],
                [
                    $toKey => $this->_->in($tmps),
                ],
                $order
            );

            //合并整合关联信息
            for ($i = 0; $i < sizeof($ds); $i++) {
                $ismany = false;
                if ($relType == 'one-to-many') {
                    $ismany = true;
                    $ds[$i][$key] = [];
                } else if ($relType == 'one-to-one') {
                    $ds[$i][$key] = null;
                }

                foreach ($res as $re) {
                    if (isset($ds[$i][$fromKey]) && $ds[$i][$fromKey] == $re[$toKey]) {
                        if ($ismany) {
                            array_push($ds[$i][$key], $re);
                        } else {
                            if ($ds[$i][$key] != null) {
                                SSLog::error('获取关联one-to-one属性值失败,有多个对应关系',
                                    $bean['propertys'][$key],
                                    $bean['collection']);
                            }
                            $ds[$i][$key] = $re;
                        }
                    }
                }

            }

        }
        return $ds;
    }

    //遍历查询全部信息,支持排序查询
    private function query($table, $where, $order, $skip = 0)
    {
        $col = $this->db->collection($table);
        if (sizeof($order) > 0) {
            foreach ($order as $k => $v) {
                $col = $col->orderBy($k, $v);
            }
        }
        $res = $col->skip($skip)->where($where)->get();
        if (!isset($res['data'])) {
            SSLog::error($res);
            return [];
        }
        $ds = $res['data'];

        $beans = $this->conf->loadByKey("db");

        //格式化特殊格式的数据
        foreach ($beans as $bean) {
            if ($bean['collection'] == $table) {
                foreach ($bean['propertys'] as $k => $val) {
                    if ($val['type'] == 'point') {

                        for ($i = 0; $i < sizeof($ds); ++$i) {
                            if (isset($ds[$i][$k])) {
                                $ds[$i][$k] = [
                                    'lng' => $ds[$i][$k]->longitude,
                                    'lat' => $ds[$i][$k]->latitude,
                                ];
                            }
                        }

                    }
                }
            }
        }

        if (sizeof($ds) == 100) {
            return array_merge($ds, $this->query($table, $where, $order, $skip + 100));
        }
        return $ds;
    }

    /**
     * 查询排序, 经纬度距离排序时只支持由近及远排序
     * @param string $property 被排序的属性名
     * @param string $order asc/desc, 在
     * @param double $lng 在排序的属性为point(经纬度)类型清空下传入此参数
     *  @param double $lat 在排序的属性为point(经纬度)类型清空下传入此参数
     */
    public function orderby($property, $order, $lng = null, $lat = null)
    {
        $this->order = [];
        $this->order['property'] = $property;
        $this->order['order'] = $order;
        $this->order['lng'] = $lng;
        $this->order['lat'] = $lat;
        return $this;
    }

    /**
     * 查询符合条件的全部数据, 关联查询中若指定排序,则只支持排序一个属性
     * @param string $beanName 对象名
     * @param string $relSkip skip
     *  @return array|bool bool/array 失败返回false 否则返回数据数组
     */
    public function getObjects($beanName, $relSkip = 0)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        if ($this->limit == null) {
            $this->limit = 100;
            $this->skip = 0;
        }

        $this->formatField($bean);

        //查询的属性中是否有关联属性
        $relRes = $this->getRealObjects($bean, $relSkip);
        if (sizeof($relRes) > 0) {
            foreach ($relRes as $key => $val) {
                $tmpkey = $bean['propertys'][$key]['relation']['fromKey'];
                //任何一个关联的信息为空,则表示查询结束
                if (sizeof($val[$tmpkey]) == 0) {
                    $this->cleanWhere();
                    return [];
                }
                $this->where($bean['propertys'][$key]['relation']['fromKey'], 'in', $val[$tmpkey]);
                unset($this->whereState[$key]);
            }
        }

        //排序格式化,距离排序单独处理
        if (sizeof($this->order) > 0) {
            if ($bean['propertys'][$this->order['property']]['type'] == 'point') {
                $this->whereState[$this->order['property']] = $this->_->geoNear([
                    'geometry' => $this->db->Point(
                        doubleVal($this->order['lng']),
                        doubleVal($this->order['lat'])),
                ]);
                $this->order = [];
            };
        }

        $trs = $this->getAs($bean);

        if ($trs === false) {
            SSLog::error($trs);
            $this->cleanWhere();
            return false;
        }

        //1.无关联键,无需继续查询
        //2.有关联键,同时关联结果信息为空,表示关联信息已查完
        if (sizeof($relRes) > 0) {
            $total = 0;
            foreach ($relRes as $key => $val) {
                foreach ($val as $k => $v) {
                    $total += sizeof($v);
                }
            }
            if ($total == 0) {
                $this->cleanWhere();
                return $this->formatProperty($bean, $trs);
            }
        }

        $res = [];
        //未查询满 继续再次查询
        if (sizeof($trs) < $this->limit && sizeof($trs) > 0) {
            $this->limit = $this->limit - sizeof($trs);
            $this->skip += 100;
            $res = $this->getObjects($beanName, $relSkip + 100);
        } else {
            //有关联信息,同时查询条数满
            $this->cleanWhere();
        }
        foreach ($res as $re) {
            array_push($trs, $re);
        }
        if ($relSkip == 0) {
            $this->cleanWhere();
            return $this->formatProperty($bean, $trs);
        }
        return $trs;

    }

    private function formatProperty($bean, $res)
    {
        $pros = $bean['propertys'];
        for ($i = 0; $i < sizeof($res); $i++) {
            $re = $res[$i];
            foreach ($re as $key => $val) {
                if (isset($res[$i][$key]) && isset($pros[$key]) && $pros[$key]['type'] == 'point') {
                    $res[$i][$key] = [
                        'lng' => $val->longitude,
                        'lat' => $val->latitude,
                    ];
                }
            }
        }
        return $res;
    }

    /**
     * @description: 查询相关联数据信息
     * @param {array} $bean 关联键的配置信息
     * @param {int} $skip
     * @return Countable|array 无关联键返回[], 否则返回包含结果的多维数组[fromKey=>[toKey]]
     */
    public function getRealObjects($bean, $skip)
    {

        $states = [];
        if (gettype($this->whereState) == 'object') {
            return [];
        }

        foreach ($this->whereState as $key => $val) {

            //1-多 查询优先
            if (!array_key_exists($key, $bean['propertys'])) {
                SSLog::error("{$bean['collection']}对象不存在查询的属性条件{$key}");
                return [];
            }
            if ($bean['propertys'][$key]['type'] == 'one-to-many' ||
                $bean['propertys'][$key]['type'] == 'one-to-one') {
                $relColName = $bean['propertys'][$key]['relation']['collection'];
                $toKey = $bean['propertys'][$key]['relation']['toKey'];
                $fromKey = $bean['propertys'][$key]['relation']['fromKey'];
                $hasRelOrder = false; //此关联属性是否需要排序查询

                $confs = [];
                $order = [];

                foreach ($val as $vk => $vl) {
                    if ($vk == 'orderBy' || $vk == 'orderby') {
                        $order = $vl;
                        $hasRelOrder = true;
                        unset($val[$vk]);
                    } else {
                        $confs[$vk] = $vl;
                    }
                }

                $exe = $this->db->collection($relColName)
                    ->field([$toKey => true])
                    ->skip($skip);

                if (sizeof($order) == 2) {
                    $exe = $exe->orderBy($order['property'], $order['order']);
                }

                $ms = $exe->where($confs)->get();
                $ms = $ms['data'];
                if (sizeof($ms) < 2) {
                    $hasRelOrder = false;
                }

                $ts = [];
                foreach ($ms as $m) {
                    array_push($ts, $m[$toKey]);
                }
                //是否有排序
                if ($hasRelOrder) {
                    $this->relOrderField = [$fromKey => $ts];
                    //从查询中剔除
                    unset($this->whereState[$key]);
                } else {
                    $states[$key] = [$fromKey => $ts];
                }
            }
        }
        return $states;
    }

    private function queryReal($property, $key)
    {
        $relColName = $property['collection'];
        $toKey = $property['relation']['toKey'];
        $fromKey = $property['relation']['fromKey'];

        $tmpstate = [];
        foreach ($this->whereState as $wk => $wv) {
            if ($wk == $key) {
                foreach ($wv as $wvk => $wvv) {
                    $tmpstate[$wvk] = $wvv;
                }
            }
        }

        $ms = $this->db->collection($relColName)
            ->field([$toKey => true])
            ->where($tmpstate)->get()['data'];
        $ts = [];
        foreach ($ms as $m) {
            array_push($ts, $m[$toKey]);
        }
        return $ts;
    }

    /**
     * @description: 更新指定条件的对象
     * @param {string} $table 集合/表名
     * @param {object} $newData 要更新的各属性值集合对象
     * @return {mix} false失败/否则返回更新条数
     */
    public function updateObjectByTable($table, $newData)
    {
        if (sizeof($this->whereState) == 0) {
            SSLog::error('未指定筛选条件');
            return false;
        }
        unset($newData['_id']);
        if (isset($this->whereState["_id"])) {
            $ls = $this->db->collection($table)->doc($this->whereState["_id"])->update($newData);
        } else {
            $ls = $this->db->collection($table)->where($this->whereState)->update($newData);
        }
        $this->cleanWhere();

        if (isset($ls['updated'])) {
            return $ls['updated'];
        }
        SSLog::error($ls);
        return false;
    }
    /**
     * @description: 更新指定条件的对象
     * @param string $beanName 集合/表名
     * @param object $newData 要更新的各属性值集合对象
     * @return int|false false失败/否则返回更新条数
     */
    public function updateObject($beanName, $newData)
    {
        if (sizeof($this->whereState) == 0) {
            SSLog::error($beanName . '未指定筛选条件');
            return false;
        }

        $bean = $this->conf->loadByKey("db")[$beanName];
        $table = $bean['collection'];
        $propertys = $bean['propertys'];

        foreach ($this->whereState as $key => $val) {
            if (!array_key_exists($key, $propertys)) {
                SSLog::error("搜索的属性{$key}未在{$table}中配置");
                return false;
            }
        }

        foreach ($newData as $key => $val) {
            if (!array_key_exists($key, $propertys)) {
                SSLog::error("更新的属性{$key}未在{$table}中配置");
                return false;
            }
            // SSLog::error($val);
            switch ($propertys[$key]['type']) {
                case 'point':
                    $newData[$key] = $this->getPoint($val);
                    break;
                case 'string':
                    if(!is_string($val)) {
                        SSLog::error($key.'数据格式错误,必须为string');
                        return false;
                    }
                    break;
                case 'bool':
                    if(!is_bool($val)) {
                        SSLog::error($key.'数据格式错误,必须为bool');
                        return false;
                    }
                    break;
                case 'int':
                    if(!is_numeric($val)){
                        SSLog::error($key.'数据格式错误,必须为数字');
                        return false;
                    }
                    break;
            }

            // if ($propertys[$key]['type'] == 'point') {
            //     SSLog::info('point');
            //     $newData[$key] = $this->getPoint($val);
            // }
        }

        unset($newData['_id']);
        if (isset($this->whereState["_id"])) {
            $ls = $this->db->collection($table)->where($this->whereState)->update($newData);
        } else {
            $ls = $this->db->collection($table)->where($this->whereState)->update($newData);
        }
        $this->cleanWhere();

        if (isset($ls['updated'])) {
            return $ls['updated'];
        }
        SSLog::error($ls);
        return false;
    }

    public function removeFields($table, $fields)
    {
        if (sizeof($this->whereState) == 0) {
            SSLog::error('未指定筛选条件');
            return false;
        }
        $tmps = [];
        foreach ($fields as $field) {
            $tmps[$field] = $this->_->remove();
        }

        if (isset($this->whereState["_id"])) {
            $ls = $this->db->collection($table)->doc($this->whereState["_id"])->update($tmps);
        } else {
            $ls = $this->db->collection($table)->where($this->whereState)->update($tmps);
        }
        $this->cleanWhere();
        if (isset($ls['updated'])) {
            return $ls['updated'];
        }
        SSLog::error($ls);
        return false;
    }

    /**
     * @description: 重置where条件
     * @param {*}
     * @return SSTcbDB
     */
    public function newWhere($key, $op, $val)
    {
        $this->whereState = [];
        $this->fields = [];
        return $this->where($key, $op, $val);
    }

    /**
     * @description:构建where条件,支持瀑布流写法
     * @param {*}  [] (] [) ()  数字区间,
     * @return SSTcbDB|bool false失败
     */
    public function where($key, $op, $val)
    {
        $_ = $this->_;

        $con = $this->buildWhere($op, $val);
        if ($con === null) {
            return false;
        }

        if (key_exists($key, $this->whereState)) {
            $this->whereState[$key] = $this->whereState[$key]->and($con);
        } else {
            $this->whereState[$key] = $con;
        }

        return $this;
    }

    /**
     * 特定字段的or条件组合
     */
    public function whereOrExp($key, $vals)
    {
        $or = $vals[0];

        for ($i = 1; $i < sizeof($vals); ++$i) {
            $or = $or->or($vals[$i]);
        }

        $this->whereState[$key] = $or;
        return $this;
    }

    public function arrayWhere($para)
    {

    }

    public function buildWhere($op, $val)
    {
        $_ = $this->_;
        switch ($op) {
            // $where
            case "=":
                return $val;
            case "==":
                return $val;
            case "===":
                return $_->eq($val);
            case '!=':
                return $_->neq($val);
            case '>':
                return $_->gt($val);
            case '>=':
                return $_->gte($val);
            case '<':
                return $_->lt($val);
            case '<=':
                return $_->lte($val);
            case '[]':
                return $_->and($_->gte($val[0]), $_->lte($val[1]));
            case '(]':
                return $_->and($_->gt($val[0]), $_->lte($val[1]));
            case '[)':
                return $_->and($_->gte($val[0]), $_->lt($val[1]));
            case '()':
                return $_->and($_->gt($val[0]), $_->lt($val[1]));
            case 'in':
                return $_->in($val);
            case 'nin':
                return $_->nin($val);
            case 'or':
                return $this->_->or($val);
            case 'like':
                return $this->db->RegExp([
                    'regexp' => $val,
                ]);
        }
        SSLog::error("查询条件构造异常,不支持的关键词");
        return null;
    }

    /**
     * @description: 生成特定查询语句,配合where()的or使用
     * @param {*}
     * @return {*}
     */
    public function command($op, $param)
    {
        switch ($op) {
            case '>':
                return $this->_->gt($param);
            case '>=':
                return $this->_->gte($param);
            case '<':
                return $this->_->lt($param);
            case '<=':
                return $this->_->lte($param);
        }
        return false;
    }

    /**
     * @description: 多参数or条件, 此函数会覆盖之前预设的where条件
     * @param {*}
     * @return {bool} true成功 false失败
     */
    public function whereOr()
    {
        $args = func_get_args();
        $numargs = func_num_args();
        if ($numargs < 2) {
            return false;
        }

        $this->whereState = $this->_->or($args);
    }

    /**
     * @description: 获取已经拼装的where条件
     * @param {*}
     * @return {*}
     */
    public function getWhere()
    {
        return $this->whereState;
    }

    public function setWhere($para)
    {
        $this->whereState = $para;
    }

    /**
     * @description: 更新数据的内容. 只修改新对象指定的数据
     * @param {array} $newData 替换的新值
     * @param {array} $table 表名称
     * @return {*} 成功返回修改的数据的条数,失败返回false
     */
    public function update($newData, $table)
    {
        $res = false;
        try {
            $data = $this->db->collection($table)->where($this->whereState)->update($newData);
            if (isset($data['updated'])) {
                $res = $data['updated'];
            } else {
                SSLog::error($data);
                $res = false;
            }
        } catch (Exception $ex) {
            SSLog::error($ex);
        }
        $this->cleanWhere();

        return $res;
    }

    /**
     * @description: 更新指定对象id的内容. 只修改新对象指定的数据
     * @param {string} $id 对象id
     * @param {array} $newData 替换的新值
     * @param {array} $table 表名称
     * @return {*} 成功返回修改的数据的条数,失败返回false
     */
    public function updateById($id, $newData, $table)
    {
        $res = false;
        try {
            $data = $this->db->collection($table)->doc($id)->update($newData);
            if (isset($data['updated'])) {
                $res = $data['updated'];
            } else {
                SSLog::error($data);
                $res = false;
            }
        } catch (Exception $ex) {
            SSLog::error($ex);
        }
        $this->cleanWhere();
        return $res;
    }

    public function test($n)
    {
        $conf = new SSConf();
        $db = $conf->loadByKey("db");
        $phpfile = getcwd() . $db['Ant']['php'];
        require_once $phpfile;
        return new $n();
    }

}
