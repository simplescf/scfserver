<?php
/*
 * @Author: tsr
 * @Date: 2020-05-19 21:25:38
 * @LastEditTime: 2021-11-04 13:31:07
 * @LastEditors: tsr
 * @Description: mysql数据库ORM
 * @FilePath: /simplescf/src/SSTcbDB.php
 */

namespace sscf;

require_once __DIR__ . "/ssautoload.php";

global $_PDO;
$_PDO = null;

class SSPdo
{
    private $tcb;
    private $db;
    private $conf;
    //
    private $fields = [];
    //
    private $outFields = [];

    private $whereState = [];
    private $order = [];
    private $_;
    private $pdo;
    private $limit;
    private $relConfs = [];
    private $beans = [];
    private $extraWheres = [];
    //关联表需要查询出的字段信息
    private $joinFields = [];
    private $extraFields = [];

    /**
     * @param $right 账号权限,default为默认,read为读账户,readwrite 为读写账号
     */
    public function __construct()
    {
        $this->conf = new SSConf();
        $this->beans = $this->conf->loadByKey("db");

        $dbset = $this->initDbSet();
        for ($i = 0; $i < 10; ++$i) {
            //预防serverless版本的TDSQL-C出现自动停止，需要自动重连
            $tmp = $this->reinit($dbset);
            if (true === $tmp) {
                break;
            } else if (intval($tmp) == 9449) {
                usleep(300 * 1000);
            } else {
                break;
            }
        }

    }

    /**
     * 数据库配置
     */
    private function initDbSet()
    {
        $scf = new SScf();
        $set = [];
        //识别执行环境
        if ($scf->isScf()) {
            //是腾讯云serverless环境
            $set = $this->conf->loadByKey("local_db");
        } else {
            //本地直接调用
            $set = $this->conf->loadByKey("remote_db");
        }

        return [
            'dsn' => "{$set['database_type']}:host={$set['server']};dbname={$set['database_name']}",
            'username' => $set['username'],
            'pwd' => $set['password'],
        ];
    }

    private function reinit($set)
    {
        global $_PDO;
        $dsn = $set['dsn'];
        if ($_PDO === null) {
            try {
                $_PDO = new \PDO($dsn, $set['username'], $set['pwd'], array(
                    \PDO::ATTR_PERSISTENT => true,
                )); //初始化一个PDO对象

            } catch (\PDOException$ex) {
                SSLog::error($ex->getMessage());
                SSLog::error($ex->getCode());
                return $ex->getCode();
            }

        } else {
            SSLog::debug('复用PDO');
        }
        $this->pdo = $_PDO;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return true;
    }

    public function getDB()
    {
        global $_PDO;
        return $_PDO;
    }

    public function getPropertys($table)
    {
        try {
            $db = $this->conf->loadByKey("remote_db")['database_name'];
            $sql = "select * from information_schema.columns where table_schema = '{$db}' and table_name = '{$table}'";
            $stmt = $this->pdo->query($sql);
            $pros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $ret = [];
            foreach ($pros as $pro) {
                $tmpkey = str_replace($table . '_', '', $pro['COLUMN_NAME']);

                $ret[$tmpkey] = [
                    'type' => $pro['DATA_TYPE'],
                    'field' => $pro['COLUMN_NAME'],
                    'required' => $pro['IS_NULLABLE'] == 'NO' ? true : false,
                    'desc' => $pro['COLUMN_COMMENT'],
                ];
            }
            return $ret;
        } catch (\PDOException$e) {
            return $e->getMessage();
        }

    }

    /**
     * 在表中插入一个对象
     * @param string $beanName collect对应的配置文件中的名称
     * @param array $obj 插入的对象数据
     * @return bool|int false失败, 否则返回新增id
     */
    public function addObject($beanName, $obj)
    {
        try {
            $bean = $this->conf->loadByKey("db")[$beanName];
            $tab = $bean['table'];
            //属性/字段关联表
            $fields = $this->getFieldByObj($bean, $obj);
            $tabFileds = [];
            $values = [];
            foreach ($fields as $field) {
                array_push($tabFileds, $field['field']);
                $attr = $field['attr'];
                foreach($bean['propertys'] as $prop){
                    if($prop['field']==$field['field']&&$prop['type']=='geometry'){
                        $attr = "ST_GeomFromText('".$field['attr']."')";
                    }
                }
                array_push($values, $attr);
            }

            $key = implode(',', $tabFileds);
            $val = implode(',', $values);

            
            $sql = "INSERT INTO `{$tab}` ({$key}) VALUES ({$val})";
            SSLog::info($sql);
            $res = $this->pdo->exec($sql);
            if (0 == $res || false === $res) {
                SSLog::error($this->pdo->errorInfo());
                return false;
            }
            return $this->pdo->lastInsertId();
        } catch (\PDOException$e) {
            SSLog::error($e->getMessage());
        }
        return false;
    }

    /**
     * 批量增加对象
     * @param string $beanName collect对应的配置文件中的名称
     * @param array $objs 插入的对象数据
     * @return bool|int false失败, 否则返回新增id
     */
    public function addObjectBatch($beanName, $objs)
    {
        try {
            $bean = $this->conf->loadByKey("db")[$beanName];
            $tab = $bean['table'];

            $values = [];

            //属性/字段关联表
            foreach ($objs as $obj) {
                $tabFileds = [];
                $tmpval = [];
                $fields = $this->getFieldByObj($bean, $obj);
                foreach ($fields as $field) {
                    array_push($tabFileds, $field['field']);
                    array_push($tmpval, $field['attr']);
                }
                $key = implode(',', $tabFileds);
                $val = implode(',', $tmpval);

                array_push($values, "({$val})");
            }
            $valtxt = implode(',', $values);
            $sql = "INSERT INTO {$tab} ({$key}) VALUES {$valtxt}";
            SSLog::info($sql);
            $res = $this->pdo->exec($sql);
            if (0 == $res || false === $res) {
                SSLog::error($this->pdo->errorInfo());
                return false;
            }
            return $this->pdo->lastInsertId();
        } catch (\PDOException$e) {
            SSLog::info($e->getMessage());
        }
        return false;
    }

    /**
     * @description: 根据条件查询匹配的数据条数
     * @param {*}
     * @return int 条数
     */
    public function count($beanName, $obj = [])
    {
        try {
            if (sizeof($obj) > 0) {
                $this->initConf($beanName, $obj);
            }

            $bean = $this->conf->loadByKey("db")[$beanName];
            $sql = $this->getCountSql($bean);
            SSLog::info($sql);
            $this->cleanWhere();
            $stmt = $this->pdo->query($sql);
            $pros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $pros[0]['count'];
        } catch (\Exception$e) {
            SSLog::error($sql);
            SSLog::error($e->getMessage());
        }
        return false;
    }

    /**
     * 执行SQL查询语句,并自动封装成对应数组
     * @param String $sql 要执行的sql语句
     * @param String $beanName 要自动封装的对象名,null表示不封装
     * @return array|false 成功或有数据返回对象数组,否则返回false
     */
    public function query($sql, $beanName = null)
    {
        try {
            // SSLog::debug($sql);
            $stmt = $this->pdo->query($sql);
            $pros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (sizeof($pros) == 0) {
                return [];
            }
            if (null === $beanName) {
                return $pros;
            }
            $bean = $this->conf->loadByKey("db")[$beanName];
            return $this->queryToObj($bean, $pros);
        } catch (\PDOException$e) {
            SSLog::error($sql);
            SSLog::error($e->getMessage());
        }
        return false;
    }

    /**
     * @description: 查询符合条件的单条数据
     * 适合确定条件只能查询出一条数据时用 , 如根据唯一索引查询
     * @param String $beanName 查询的对象名
     * @return array|bool 查询失败或无数据返回false, 否则返回对应的数组类型数据
     */
    public function getOneObject($beanName, $obj = [])
    {
        try {
            if (sizeof($obj) > 0) {
                $this->initConf($beanName, $obj);
            }

            $bean = $this->conf->loadByKey("db")[$beanName];
            $this->limit(0, 1);
            $sql = $this->getSelectSql($bean);
            SSLog::info($sql);
            $stmt = $this->pdo->query($sql['sql']);
            $pros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $rets = $this->dbToObj($bean, $pros);
            if (sizeof($rets) > 0) {
                $this->cleanWhere();
                return $rets[0];
            }
            $this->cleanWhere();
            return false;
        } catch (\PDOException$e) {
            SSLog::error($e->getMessage());
        }
        $this->cleanWhere();
        return false;
    }

    /**
     * 根据对象查询对应的表字段名
     */
    private function getFieldByObj($bean, $data)
    {
        $tmps = [];
        foreach ($data as $key => $val) {
            foreach ($bean['propertys'] as $pk => $pv) {
                if ($key == $pk && isset($pv['field'])) {
                    $tmp = null;
                    if ('varchar' == $pv['type'] ||
                        'text' == $pv['type'] ||
                        'datetime' == $pv['type'] ||
                        'date' == $pv['type']) {
                        $tmp = "'{$val}'";
                    } else {
                        $tmp = $val;
                    }
                    if (null === $tmp) {
                        continue;
                    }

                    array_push($tmps,
                        [
                            'attr' => $tmp,
                            'field' => $pv['field'],
                        ]
                    );
                }
            }
        }
        return $tmps;
    }

    /**
     * 是否为可供直接查询的字段
     * @return string|false 不能直接查询false,否则返回数据库对应字段值
     */
    private function isCanQueryField($bean, $key)
    {
        $pros = $bean['propertys'];

        if (array_key_exists($key, $pros)) {
            $type = $pros[$key]['type'];
            //关联字段
            if ('one-to-one' == $type || 'one-to-many' == $type || 'many-to-one' == $type || 'one-to-count' == $type) {
                return false;
            }
            return $pros[$key]['field'];
        }
        //未配置的字段
        return false;
    }

    /**
     * 查询全部的one-to-many字段
     */
    private function getManyFields($bean)
    {
        $fs = $this->getFields($bean);
        $pros = $bean['propertys'];

        $tmps = [];
        foreach ($fs as $field) {
            if ($pros[$field]['type'] == 'one-to-many') {
                array_push($tmps, $field);
            }
        }
        return $tmps;
    }

    /**
     * 查询真实需要查询的全部字段列表
     */
    private function getFields($bean)
    {
        $tmps = [];
        //用户配置的字段信息

        if (sizeof($this->outFields) > 0) {
            foreach ($bean['propertys'] as $key => $val) {
                if (false === array_search($key, $this->outFields)) {
                    array_push($tmps, $key);
                }
            }
        } else {
            if (sizeof($this->fields) == 0) {
                foreach ($bean['propertys'] as $key => $val) {
                    array_push($tmps, $key);
                }
            } else {
                foreach ($this->fields as $val) {
                    array_push($tmps, $val);
                }
            }
        }

        return $tmps;
    }

    /**
     * 设置要查询出的关联表的字段
     */
    public function setJoinFields($objName, $fields)
    {
        $this->joinFields[$objName] = $fields;
        return $this;
    }

    /**
     * 格式化出可以查询的数据库字段
     * @return array ['db的table'=>[列名字]]
     */
    private function formatField($bean)
    {
        $fields = [];
        //用户配置的字段信息
        $props = $this->getFields($bean);
        foreach ($props as $prop) {
            $field = $this->isCanQueryField($bean, $prop);
            if (false !== $field) {
                if (!array_key_exists($bean['table'], $fields)) {
                    $fields[$bean['table']] = [];
                }
                
                $tmpfield = "{$bean['table']}.{$field} as {$bean['table']}_{$field}";
                if('geometry'==$bean['propertys'][$prop]['type']){
                    $tmpfield = "ST_AsText({$bean['table']}.{$field}) {$bean['table']}_{$field}";
                }
                array_push($fields[$bean['table']], $tmpfield);
            } else {
                //特殊类型字段
                if (array_key_exists($prop, $bean['propertys'])) {
                    if ($bean['propertys'][$prop]['type'] == 'one-to-one'
                        //|| $bean['propertys'][$tmp]['type'] == 'one-to-many'
                    ) {
                        $beans = $this->conf->loadByKey("db");
                        $objname = $bean['propertys'][$prop]['relation']['object'];
                        if (isset($this->joinFields[$objname])) {
                            $tmps = [];
                            $jfs = $this->joinFields[$objname];
                            $tmpBean = $this->conf->loadByKey("db")[$objname];
                            SSLog::info($tmpBean);
                            foreach ($jfs as $objName) {
                                array_push($tmps, $this->isCanQueryField($tmpBean, $objName));
                            }
                        } else {
                            $tmps = $this->getFieldByObjectName($objname);
                        }

                        $objtable = $beans[$objname]['table'];
                        $fields[$objtable] = [];
                        foreach ($tmps as $tmp) {
                            $fq = $objtable . "." . $tmp . ' AS ' . $objtable . "_" . $tmp;
                            foreach ($beans[$objname]['propertys'] as $prop) {
                                if ($prop['field'] == $tmp && $prop['type'] == 'geometry') {
                                    $fq = 'ST_AsText(' . $objtable . "." . $tmp . ') ' . $objtable . "_" . $tmp;
                                }
                            }
                            array_push($fields[$objtable], $fq);
                        }
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * 格式化表关联信息即join信息
     * one-to-one或many-to-one时才会使用join
     * one-to-many需要单独查询
     */
    private function getJoin($bean)
    {
        $fields = $this->formatField($bean);

        $pros = $bean['propertys'];
        $beans = $this->conf->loadByKey("db");
        $joins = [];
        foreach ($pros as $pro) {
            if ($pro['type'] == 'one-to-one' ||
                $pro['type'] == 'many-to-one'
                // || $pro['type'] == 'one-to-many'
            ) {
                $toPros = $beans[$pro['relation']['object']]['propertys'];
                $relTable = $beans[$pro['relation']['object']]['table'];
                if (array_key_exists($relTable, $fields)) {
                    array_push($joins, [
                        'toobj' => $pro['relation']['object'],
                        'totable' => $beans[$pro['relation']['object']]['table'],
                        'fromtable' => $bean['table'],
                        'fromfield' => $pros[$pro['relation']['fromProperty']]['field'],
                        'tofield' => $toPros[$pro['relation']['toProperty']]['field'],
                    ]);
                }
            }
        }
        return $joins;
    }

    /**
     * 查询指定对象下全部可以直接查询的表字段名
     */
    private function getFieldByObjectName($table)
    {
        $beans = $this->conf->loadByKey("db");
        $fiedls = [];
        if (array_key_exists($table, $beans)) {
            $pros = $beans[$table]['propertys'];
            foreach ($pros as $key => $pro) {
                $tmp = $this->isCanQueryField($beans[$table], $key);
                if ($tmp !== false) {
                    array_push($fiedls, $tmp);
                }
            }
        }
        return $fiedls;
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
        $this->extraWheres = [];
        $this->extraFields = [];
    }

    /**
     * 设置返回的字段
     * 若要扣除某个属性不返回, 属性名前追加-,此功能和普通返回属性不兼容
     */
    public function field()
    {
        foreach (func_get_args() as $para) {
            if (preg_match('/^-(.*)/', $para, $match)) {
                array_push($this->outFields, $match[1]);
            } else {
                array_push($this->fields, $para);
            }
        }
        return $this;
    }

    /**
     * 以数组形式设置返回的字段
     * @return $this
     */
    public function fieldByArray($args)
    {
        foreach ($args as $para) {
            if (preg_match('/^-(.*)/', $para, $match)) {
                array_push($this->outFields, $match[1]);
            } else {
                array_push($this->fields, $para);
            }
        }
        return $this;
    }

    public function limit($skip, $limit)
    {
        $this->skip = $skip;
        $this->limit = $limit;
        return $this;
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

    public function joinConf()
    {

    }

    /**
     * where条件组装
     */
    private function getWhereConf($bean)
    {
        $ands = [];
        foreach ($this->extraWheres as $ex) {
            array_push($ands, $ex);
        }

        if (sizeof($this->whereState) == 0) {
            return $ands;
        }

        foreach ($this->whereState as $where) {
            if ($where[0] == 'or' && $where[1] == 'or') {
                $tmps = $where[2];
                $tmpsql = [];
                foreach ($tmps as $tmp) {
                    $aa = [];
                    foreach ($tmp as $tt) {
                        array_push($aa, $this->getSqlConf($bean, $tt));
                    }
                    array_push($tmpsql, implode(' AND ', $aa));
                }
                array_push($ands, "(" . implode(' OR ', $tmpsql) . ")");
            } else {
                array_push($ands, $this->getSqlConf($bean, $where));
            }
        }

        return $ands;
    }

    /**
     * sql语句where中单个条件
     * @param $conf array
     */
    private function getSqlConf($bean, $conf)
    {
        $tab = $bean['table'];
        $pros = $bean['propertys'];

        if (array_key_exists($conf[0], $pros)) {
            $pro = $pros[$conf[0]];

            if (is_array($conf[2])) {
                if ($this->isStr($pro['type'])) {
                    for ($i = 0; $i < sizeof($conf[2]); ++$i) {
                        $conf[2][$i] = "'{$conf[2][$i]}'";
                    }
                }
                $vt = implode(',', $conf[2]);
                $op = '';
                if ($conf[1] == 'in') {
                    $op = 'in';
                } else if ($conf[1] == 'nin') {
                    $op = 'not in';
                } else {
                    SSLog::error('数组检索条件异常');
                }
                return "{$tab}.{$pro['field']} {$op} ({$vt})";
            } else if (is_null($conf[2])) {
                //null条件组装
                if ($conf[1] == '=' || $conf[1] == '==') {
                    return "{$tab}.{$pro['field']} is null";
                }
                return "{$tab}.{$pro['field']} is not null";
            } else if ($this->isStr($pro['type'])) {
                return "{$tab}.{$pro['field']} {$conf[1]} {$conf[2]}";
            } else {
                return "{$tab}.{$pro['field']} {$conf[1]} {$conf[2]}";
            }
        } else {

        }
        SSLog::error("异常属性{$conf[0]},未对表{$tab}配置对应属性");
        return false;
    }

    private function getCountSql($bean)
    {
        $tab = $bean['table'];
        //where条件
        $confs = $this->getWhereConf($bean);
        $where = '';
        if (sizeof($confs) > 0) {
            $where = ' WHERE ' . implode(' AND ', $confs);
        }

        return "SELECT COUNT(*) AS count FROM `{$tab}` {$where}";
    }

    /**
     * 配置的额外where查询条件,附加在主表查询条件中
     * @param array ['beanname'=>sql] 关联条件
     */
    public function setExtraWhere($sqls)
    {
        foreach ($sqls as $beanName => $sql) {
            $beans = $this->conf->loadByKey("db");
            if (isset($beans[$beanName])) {
                array_push($this->extraWheres, $sql);
            }
        }
    }

    /**
     * 配置额外的查询字段,附加在查询中
     * @param array ['fieldname'=>sql] 关联条件
     */
    public function setExtraField($sqls)
    {
        foreach ($sqls as $fieldname => $sql) {
            $this->extraFields[$fieldname] = $sql;
        }
    }

    /**
     * 配置的one-to-many中额外关联条件, 仅支持纯sql语句
     * @param array ['obj'=>[field,value]] 关联条件
     */
    public function setJoinConf($confs)
    {
        foreach ($confs as $key => $conf) {
            $this->relConfs[$key] = $conf;
        }
        return $this;
    }

    private function getSelectSql($bean)
    {
        $tab = $bean['table'];
        //属性/字段关联表
        $fields = $this->formatField($bean);

        $tmpfields = [];
        foreach ($fields as $field) {
            // if ($prop['field'] == $tmp && $prop['type'] == 'geometry') {
            //     $fq = 'ST_AsText(' . $objtable . "." . $tmp . ') ' . $objtable . "_" . $tmp;
            // }
            
            array_push($tmpfields, implode(',', $field));
        }

        if (sizeof($this->extraFields) > 0) {
            foreach ($this->extraFields as $k => $field) {
                array_push($tmpfields, '(' . $field . ') as ' . $k);
            }
        }
        $keys = implode(',', $tmpfields);

        //where条件
        $where = implode(' AND ', $this->getWhereConf($bean));
        //本对象的多表关联

        $joins = $this->getJoin($bean);

        $joinSql = [];

        foreach ($joins as $join) {
            $jt = $join['totable'];

            $tmpsql = "LEFT JOIN `{$jt}` ON
            `{$join['fromtable']}`.{$join['fromfield']}=`{$jt}`.{$join['tofield']}";

            if (isset($this->relConfs[$join['toobj']])) {
                $tmps = [];
                foreach ($this->relConfs[$join['toobj']] as $k => $v) {
                    $tmpOneWhere = $this->getOneWhere($this->beans[$join['toobj']], $k, $v);
                    array_push($tmps, $tmpOneWhere);                 
                }
                if (sizeof($tmps) > 0) {
                    $tmpsql .= " AND " . implode(" AND ", $tmps);
                }
            }
            array_push($joinSql, $tmpsql);
        }

        $joinSql = implode(' ', $joinSql);

        $order = '';
        if (isset($this->order['property'])) {
            if (isset($bean['propertys'][$this->order['property']]['field'])) {
                $key = $bean['propertys'][$this->order['property']]['field'];
                $order = "ORDER BY {$bean['table']}." . $key . ' ' . $this->order['order'];
            } else {
                $order = "ORDER BY " . $this->order['property'] . ' ' . $this->order['order'];
            }

        }

        $limit = '';
        if ($this->limit != null) {
            $limit = "LIMIT {$this->skip}, {$this->limit}";
        }

        $sql = "SELECT {$keys} FROM `{$tab}` {$joinSql} ";
        if ($where != '') {
            $sql .= " WHERE {$where} ";
        }
        $sql .= "{$order} {$limit}";

        return ['sql' => $sql, 'join' => $joins];
    }

    private function isStr($type)
    {
        $type = strtolower($type);
        if ($type == 'char' || $type == 'varchar' || $type == 'tinytext' ||
            $type == 'text' || $type == 'mediumtext' || $type == 'longtext' ||
            $type == 'enum' || $type == 'set' || $type == 'datetime' || $type == 'date') {
            return true;
        }
        return false;
    }

    private function getUpdateSql($data, $bean)
    {
        $tab = $bean['table'];

        $nqs = [];
        SSLog::info($data);
        foreach ($data as $key => $val) {

            if (array_key_exists($key, $bean['propertys'])) {
                $tmp = '';
                $type = $bean['propertys'][$key]['type'];
                if ($this->isStr($type)) {
                    $tmp = "{$bean['propertys'][$key]['field']}='{$val}'";
                } else if (is_null($val)) {
                    $tmp = $bean['propertys'][$key]['field'] . '=null';
                } else {
                    $tmp = $bean['propertys'][$key]['field'] . '=' . $val;
                }
                array_push($nqs, $tmp);
            }
        }
        SSLog::info($nqs);
        $set = implode(',', $nqs);

        //where条件
        $where = implode(' AND ', $this->getWhereConf($bean));
        return "UPDATE `{$tab}` SET {$set} WHERE {$where}";
    }

    private function getDeleteSql($bean)
    {
        $tab = $bean['table'];
        $confs = $this->getWhereConf($bean);
        if (sizeof($confs) == 0) {
            SSLog::error('删除语句条件不能为空');
            return false;
        }
        //where条件
        $where = implode(' AND ', $confs);
        return "DELETE FROM {$tab} WHERE {$where}";
    }

    /**
     * 将sql语句查询的结果转换成对象
     * @param string $beanName 要转换的对象名称,需在配置文件中配置
     * @param array $dbres 要转换的查询结果,一维数组
     */
    public function dbToBean($beanName, $dbres)
    {
        $bean = $this->conf->loadByKey("db")[$beanName];
        $pros = $bean['propertys'];

        $tmp = [];
        foreach ($pros as $key => $val) {
            $field = $this->isCanQueryField($bean, $key);
            if ($field !== false) {
                $tmpkey = $val['field'];
                if (array_key_exists($tmpkey, $dbres)) {
                    $tmp[$key] = $dbres[$tmpkey];
                }
            }
        }

        return $tmp;
    }

    /**
     * 查询结果转换成bean对象返回
     */
    private function dbToObj($bean, $rets)
    {

        $pros = $bean['propertys'];
        $retobjs = [];
        $beans = $this->conf->loadByKey("db");

        //主表返回信息
        foreach ($rets as $ret) {
            $tmp = [];
            foreach ($pros as $key => $val) {
                $field = $this->isCanQueryField($bean, $key);
                if ($field !== false) {
                    $tmpkey = $bean['table'] . '_' . $val['field'];
                    if (array_key_exists($tmpkey, $ret)) {
                        $tmp[$key] = $ret[$tmpkey];
                    }
                }
            }
            foreach ($this->extraFields as $key => $sql) {
                if (array_key_exists($key, $ret)) {
                    $tmp[$key] = $ret[$key];
                }
            }
            array_push($retobjs, $tmp);
        }

        //join关联表信息
        $fields = $this->formatField($bean);

        //关联表的对象配置
        $joinObjs = [];
        foreach ($fields as $key => $val) {
            if ($key != $bean['table']) {
                foreach ($beans as $objkey => $tmp) {
                    if ($tmp['table'] == $key) {
                        $joinObjs[$objkey] = $tmp;
                    }
                }
            }
        }

        //one-to-one 关联字段的查询结果格式化
        foreach ($pros as $prokey => $pro) {
            if (isset($pro['relation'])) {
                $relObjName = $pro['relation']['object'];
                if (array_key_exists($relObjName, $joinObjs)) {
                    for ($i = 0; $i < sizeof($rets); ++$i) {
                        //格式化关联的对象
                        $joinbean = $beans[$relObjName];
                        $tmpobj = [];
                        foreach ($joinbean['propertys'] as $key => $val) {
                            //配置文件中的关联属性没有返回值
                            if (!isset($val['relation'])) {
                                $tmppkey = $joinbean['table'] . '_' . $val['field'];
                                if (array_key_exists($tmppkey, $rets[$i])) {
                                    $tmpobj[$key] = $rets[$i][$tmppkey];
                                }
                            }
                        }
                        $retobjs[$i][$prokey] = $tmpobj;
                    }
                }
            }
        }

        return $this->formatMany($bean, $pros, $retobjs);

    }

    /**
     * 查询并格式化对象中one-to-many部分
     */
    private function formatMany($bean, $pros, $retobjs)
    {
        //用户代码要查询的 one-to-many 的字段列表
        $manys = $this->getManyFields($bean);
        foreach ($manys as $fd) {
            $mf = $pros[$fd]['relation']['fromProperty'];
            $sf = $pros[$fd]['relation']['toProperty'];

            //主表未查询出结果, 或查询的内容未返回主附表关联字段, 则将需要查询的many字段直接置为空数组
            if (sizeof($retobjs) == 0 || !isset($retobjs[0][$mf])) {
                for ($i = 0; $i < sizeof($retobjs); ++$i) {
                    $retobjs[$fd] = [];
                }
                continue;
            }
            $ids = [];
            foreach ($retobjs as $ret) {
                array_push($ids, $ret[$mf]);
            }
            //配置关联条件
            $relObjName = $pros[$fd]['relation']['object']; //主表关联的对象
            //主附表关联的主信息转为查询条件
            if (isset($this->relConfs[$relObjName])) {
                $this->relConfs[$relObjName][$pros[$fd]['relation']['toProperty']]
                = $ids;
            } else {
                $this->relConfs[$relObjName] = [
                    $pros[$fd]['relation']['toProperty'] => $ids,
                ];
            }
            
            $joinRets = $this->queryJoin($fd, $pros, $this->beans, $sf);
            for ($i = 0; $i < sizeof($retobjs); ++$i) {
                $rs = [];
                if (isset($joinRets[$retobjs[$i][$mf]])) {
                    $rs = $joinRets[$retobjs[$i][$mf]];
                }
                $retobjs[$i][$fd] = $rs;
            }
        }
        return $retobjs;
    }

    /**
     * 查询关联的信息
     * @param string $cateField 查询完毕后,分类的id
     */
    private function queryJoin($field, $pros, $beans, $cateField)
    {
        // SSLog::info($field, $pros, $cateField);
        $relObjName = $pros[$field]['relation']['object'];
        $relObj = $beans[$relObjName];
        $sql = "select * from " . $beans[$pros[$field]['relation']['object']]['table'];
        
        //组合条件
        $confs = $this->getConfs($relObjName, $this->relConfs[$relObjName]);

        $joinWhere = [];
        foreach ($confs as $conf) {
            array_push($joinWhere, $this->getSqlConf($relObj, $conf));
        }

        $sql = $sql . ' where ' . implode(' and ', $joinWhere)." limit 100";
        $stmt = $this->pdo->query($sql);
        $cates = [];
        if ($stmt !== false) {
            $rets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rets as $ret) {
                $tmp = $this->dbToBean($relObjName, $ret);
                if (isset($cates[$tmp[$cateField]])) {
                    array_push($cates[$tmp[$cateField]], $tmp);
                } else {
                    $cates[$tmp[$cateField]] = [$tmp];
                }
            }
        }
        return $cates;
    }

    /**
     * sql语句直接查询的转换
     */
    private function queryToObj($bean, $rets)
    {
        $pros = $bean['propertys'];
        $retobjs = [];

        foreach ($rets as $ret) {
            $tmp = [];
            foreach ($pros as $key => $val) {
                $field = $this->isCanQueryField($bean, $key);
                if ($field !== false) {
                    $tmpkey = $val['field'];
                    if (array_key_exists($tmpkey, $ret)) {
                        $tmp[$key] = $ret[$tmpkey];
                    }
                }
            }
            array_push($retobjs, $tmp);
        }

        return $retobjs;
    }

    /**
     * 根据指定的对象属性进行查询条件的自动配置
     */
    private function initConf($beanName, $confs)
    {
        $set = $this->conf->loadByKey("db");
        if (!isset($set[$beanName])) {
            SSLog::error("未配置{$beanName}对应的表");
            return;
        }
        $pros = $set[$beanName]['propertys'];
        foreach ($confs as $key => $val) {
            $propertyName = $key;
            $op = '=';
            if (preg_match("/(.*)(\>\=|\<\=|\!\=)$/i", $key, $match)) {
                //>= <= !=
                $propertyName = $match[1];
                $op = $match[2];
            } else if (preg_match('/(.*)([=,>,<,\%])$/', $key, $match)) {
                //= > < %
                $propertyName = $match[1];
                $op = $match[2];
                if ($op == '%') {
                    $op = 'like';
                }
                if ($op == '=' && is_array($val)) {
                    $op = 'in';
                }

            } else if (preg_match('/(.*)(\!\[\])$/', $key, $match)) {
                //![]
                $propertyName = $match[1];
                $op = 'nin';
            } else if (preg_match('/(.*)(\[\])$/', $key, $match)) {
                //[]
                $propertyName = $match[1];
                $op = 'in';
            } else {
                if (is_array($val)) {
                    $op = 'in';
                }
            }
            if (array_key_exists($propertyName, $pros)) {
                $this->where($propertyName, $op, $val);
            } else {
                SSLog::error("{$beanName}未配置属性{$propertyName}");
            }
        }

    }

    /**
     * 将conf条件格式化
     * @param array [property=>value]
     * @return array [[name, op, value]]
     */
    private function getConfs($beanName, $confs)
    {
        $set = $this->conf->loadByKey("db");
        if (!isset($set[$beanName])) {
            SSLog::info("未配置{$beanName}对应的表");
            return;
        }
        $pros = $set[$beanName]['propertys'];
        $ret = [];

        foreach ($confs as $key => $val) {
            $propertyName = $key;
            $op = '=';
            if (preg_match("/(.*)(\>\=|\<\=|\!\=)$/i", $key, $match)) {
                //>= <= !=
                $propertyName = $match[1];
                $op = $match[2];
            } else if (preg_match('/(.*)([=,>,<,\%])$/', $key, $match)) {
                //= > < %
                $propertyName = $match[1];
                $op = $match[2];
                if ($op == '%') {
                    $op = 'like';
                }
            } else if (is_array($val)) {
                $propertyName = $key;
                $op = 'in';

                if (preg_match('/(.*)(\!\[\])$/', $key, $match)) {
                    //![]
                    $propertyName = $match[1];
                    $op = 'nin';
                } else if (preg_match('/(.*)(\[\])$/', $key, $match)) {
                    //[]
                    $propertyName = $match[1];
                    $op = 'in';
                }
                if($this->isStr($pros[$propertyName]['type'])){
                    for ($i = 0; $i < sizeof($val); ++$i) {
                        $val[$i] = "'{$val[$i]}'";
                    }
                }
                $val = "(" . implode(',', $val) . ")";
            }
            if (array_key_exists($propertyName, $pros)) {
                array_push($ret, [$propertyName, $op, $val]);
            }
        }
        return $ret;
    }

    /**
     * 查询符合条件的全部数据, 关联查询中若指定排序,则只支持排序一个属性
     * @param string $beanName 对象名
     * @param array $obj 要查询的对象属性，仅考虑属性名和配置的字段一样的
     * @param string $relSkip skip
     *  @return array|bool bool/array 失败返回false 否则返回数据数组
     */
    public function getObjects($beanName, $obj = [])
    {
        try {
            if (sizeof($obj) > 0) {
                $this->initConf($beanName, $obj);
            }
            $bean = $this->conf->loadByKey("db")[$beanName];
            $sql = $this->getSelectSql($bean);
            SSLog::info($sql);
            $stmt = $this->pdo->query($sql['sql']);
            if ($stmt === false) {
                $this->cleanWhere();
                return false;
            }
            $pros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $ret = $this->dbToObj($bean, $pros);
            $this->cleanWhere();
            return $ret;
        } catch (\PDOException$e) {
            SSLog::error($e->getMessage());
        }
        $this->cleanWhere();
        return false;
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

        return $states;
    }

    /**
     * @description: 更新指定条件的对象
     * @param string $beanName 集合/表名
     * @param object $newData 要更新的各属性值集合对象
     * @param array $obj 条件
     * @return int|false false失败/否则返回更新条数
     */
    public function updateObject($beanName, $newData, $para = [])
    {
        if (sizeof($para) > 0) {
            $this->initConf($beanName, $para);
        }

        if (sizeof($this->whereState) == 0) {
            SSLog::error($beanName . '未指定筛选条件');
            return false;
        }
        $bean = $this->conf->loadByKey("db")[$beanName];
        $sql = $this->getUpdateSql($newData, $bean);
        SSLog::info($sql);
        $rows = $this->pdo->exec($sql);
        SSLog::info($rows);
        $this->cleanWhere();
        if (false === $rows) {
            SSLog::error($this->pdo->errorInfo());
            return false;
        }
        return $rows;
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
        array_push($this->whereState, $this->buildWhere($key, $op, $val));
        return $this;
    }

    /**
     * 特定字段的or条件组合
     * @param 每个输入条件为二维数组 元素为[key, op, val]
     */
    public function whereOrExp()
    {
        // ['or', 'or', 条件数组]为or
        $confs = ['or', 'or', []];
        SSLog::info(func_get_args());
        foreach (func_get_args() as $paras) {
            $tmp = [];

            foreach ($paras as $para) {
                array_push($tmp, $this->buildWhere($para[0], $para[1], $para[2]));
            }
            array_push($confs[2], $tmp);
        }

        array_push($this->whereState, $confs);
        return $this;
    }

    /**
     * 单一查询条件转sql的where语句
     */
    private function getOneWhere($bean, $key, $val)
    {
        $conf = $this->formatWhere($key, $val);
        $conf = $this->buildWhere($conf[0], $conf[1], $conf[2]);
        $wh = $this->getSqlConf($bean, $conf);
        return $wh;
    }

    /**
     * 格式化查询参数
     */
    private function formatWhere($key, $val)
    {
        $op = '=';
        $propertyName = $key;
        $val = $val;
        $isarray = is_array($val);
        if (preg_match("/(.*)(\>\=|\<\=|\!\=)$/i", $key, $match)) {
            //>= <= !=
            $propertyName = $match[1];
            $op = $match[2];
        } else if (preg_match('/(.*)([=,>,<,\%])$/', $key, $match)) {
            //= > < %
            $propertyName = $match[1];
            $op = $match[2];
            if ($op == '%') {
                $op = 'like';
            }
            if ($op == '=' && $isarray) {
                $op = 'in';
            }
        } else if (preg_match('/(.*)(\!\[\])$/', $key, $match)) {
            //![]
            $propertyName = $match[1];
            $op = 'nin';
        } else if (preg_match('/(.*)(\[\])$/', $key, $match)) {
            //[]
            $propertyName = $match[1];
            $op = 'in';
        } else if ($isarray) {
            $op = 'in';
        }
        return [$propertyName, $op, $val];
    }

    public function buildWhere($key, $op, $val)
    {
        switch ($op) {
            case '==':
            case '!=':
            case "=":
            case '>':
            case '>=':
            case '<':
            case '<=':
                if ($op == '==') {
                    $op = '=';
                }
                if (is_string($val)) {
                    return [$key, $op, $this->pdo->quote($val)];
                }
                return [$key, $op, $val];
            case '[]':
                return [
                    [$key, '>=', $val[0]],
                    [$key, '<=', $val[1]],
                ];
            case '(]':
                return [
                    [$key, '>', $val[0]],
                    [$key, '<=', $val[1]],
                ];
            case '[)':
                return [
                    [$key, '>=', $val[0]],
                    [$key, '<', $val[1]],
                ];
            case '()':
                return [
                    [$key, '>', $val[0]],
                    [$key, '<', $val[1]],
                ];
            case "in":
                // return [$key, 'in', '(' . implode(',', $val) . ')'];
                return [$key, 'in', $val];
            case 'nin':
                return [$key, 'not in', $val];
            case 'like':
                return [$key, 'like', "'{$val}'"];
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
     * 物理删除数据库记录
     */
    public function deleteObject($beanName)
    {
        if (sizeof($this->whereState) == 0) {
            SSLog::error($beanName . '未指定条件');
            return false;
        }
        $bean = $this->conf->loadByKey("db")[$beanName];
        $sql = $this->getDeleteSql($bean);
        SSLog::info($sql);
        $rows = $this->pdo->exec($sql);
        $this->cleanWhere();
        if (false === $rows) {
            SSLog::error($this->pdo->errorInfo());
            return false;
        }
        return $rows;
    }

}
