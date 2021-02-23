<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', 0);
header("Content-Type: text/html; charset=UTF-8");

define("_HOMEPATH", "/home/mail/domaindir/");
define("_FILE_TIME", date('YmdHis') . strstr(microtime(true), "."));
define("_SAVE_PATH", "/data/mail_api");
define("_SAVE_PATH_YM", _SAVE_PATH . "/" . date('Ymd'));

mkdir(_SAVE_PATH_YM);
chown(_SAVE_PATH_YM, "nobody");
chgrp(_SAVE_PATH_YM, "nobody");

//필요값
#########################################

define("_DOMAIN", "hoseo.edu"); //도메인

define("_ROOT_ORGAN", ""); // 업체명(조직도 루트)
define("_DELEMETER", ""); // 데이터 구분자
define("_CHECK_SYNC_COLUMN_LIST", ['name','sosok','gradename','groupname']); // 해당 데이터 변경시 데이터 업데이트(기본 : 이름, 소속, 직급, 그룹)
define("_EXCEPT_GROUPNAME", []); // groupname 건너뛰기(array) ex)학생, 학부생, etc

define("_GROUP_DATA_JSON", ""); //조직도 데이터 json
define("_GROUP_DATA_CSV", ""); //조직도 데이터 csv
define("_IGNORE_EXCEPT_GROUP_DATA", TRUE); // 조직도 예외데이터 처리방법

define("_USER_DATA_JSON", ""); //사용자 데이터 json
define("_USER_DATA_CSV", ""); //사용자 데이터 csv
define("_IGNORE_EXCEPT_USER_DATA", TRUE); // 사용자 예외데이터 처리방법
define("_EXCEPT_USER_COLUMN", ""); // 사용자 예외데이터 처리방법
define("_ALL_USER_UPDATE", FALSE); // 사용자 예외데이터 처리방법

#########################################


define("_DBS_PATH", "/home/mail/domaindir/" . _DOMAIN  . "/_DBS/accounts.dbs");

// 조직도 컬럼 매핑
$group_mapping_list=[
    'code'  =>  '',
    'name'  =>  '',
    'parentcode'  =>  '',
    'depth'  =>  '',
    'sortorder'  =>  '',
    ];

// 사용자 컬럼 매핑
$user_mapping_list=[
    'id'  =>  '', //유저아이디 (id 넣을경우 사용자 추가됨, 없으면 업데이트만)
    'sabeon'  =>  '', //사번
    'sosok'  =>  '', // 부서, 소속 seq
    'sosokname'  =>  '', //부서, 소속 이름
    'sosok_order'  => '', //부서내 사용자 순서
    'name'  =>  '', //부서내 사용자 순서
    'hp'  =>  '', //핸드폰
    'tel'  =>  '', //전화
    'email'  =>  '', //email
    'gradename'  =>  '', //직급
    'groupname'  =>  '', //그룹
    ];

//그룹데이터 동기화
#sync_group_data($group_mapping_list);

//회원 데이타
sync_user_data($user_mapping_list);

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function sync_group_data($group_mapping_list)
{
    //조직도 데이타
    unset($group);
    if (!empty(_GROUP_DATA_JSON)) {
        $group_data = file_get_contents(_GROUP_DATA_JSON);
        $type = 'json';
    } else {
        $group_data = file_get_contents(_GROUP_DATA_CSV);
        $type = 'csv';
    }
    $list_arr = get_list_arr($group_data, $type);

    //조직도 root
    $root_code = 0;
    $key = array_search(_ROOT_ORGAN, array_column($list_arr, 'name'));
    $root_code = $list_arr[$key]['code'];
    $control_depth_level = $list_arr[$key]['depth'];
    $sync_start = TRUE;
    $error_list = [];

    foreach ($list_arr as $row) {
        $row=(array)$row;
        unset($put_data);
        unset($arr);

        //최상위 조직 예외처리
        if ($row['name'] == _ROOT_ORGAN) {
            continue;
        }
        if ($row['parentcode'] == $root_code){
            $row['parentcode'] = '1';
        }

        //매핑값 예외처리 및 예외처리방법
        $column_list=array_keys(array_filter($group_mapping_list));
        foreach ($column_list as $column) {
            if(in_array($column,['code','parentcode','sortorder'])){
                $put_data[$column] = code_conv($row[$group_mapping_list[$column]]);
            }else if(in_array($column,['name'])){
                $put_data[$column] = check_encoding($row[$group_mapping_list[$column]]);
            }else if($column == 'depth'){
                $put_data[$column] = ($row[$group_mapping_list[$column]] - $control_depth_level);
            }

            if(empty($row[$group_mapping_list[$column]])){
                $error_list[$column.' is null'] = $row;
                if(_IGNORE_EXCEPT_GROUP_DATA == FALSE){
                    break;
                }
            }
        }

        //연동 될 조직도
        if ($put_data['code']) {
            $group[] = array_filter($put_data);
        }
    }

    foreach ((array) $group as $key => $value) {
        $sort[$key] = $value['depth'];
    }
    array_multisort($sort, SORT_ASC, $group);

    //예외상황 처리방법
    if( !empty($error_list) && _IGNORE_EXCEPT_GROUP_DATA == FALSE ){
        $sync_start = FALSE;
    }
    file_put_contents('./error_list.txt', print_r($error_list, true));

    //실제 api전송
    if ($group && $sync_start) {
        file_put_contents(_SAVE_PATH_YM . "/" . _DOMAIN . "_group_" . _FILE_TIME, json_encode($group), FILE_APPEND);
        @unlink(_SAVE_PATH . "/" . _DOMAIN . "_group");
        shell_exec("ln -s " . _SAVE_PATH_YM . "/" . _DOMAIN . "_group_" . _FILE_TIME . " " . _SAVE_PATH . "/" . _DOMAIN . "_group");
        $base64_domain = base64_encode("mbox_host=" . _DOMAIN . "&file_name=" . _SAVE_PATH_YM . "/" . _DOMAIN . "_group_" . _FILE_TIME);
        echo $str = "php /usr/local/plug/html/index.php mail_api group index {$base64_domain} > " . _SAVE_PATH_YM . "/" . _DOMAIN . "_group_" . _FILE_TIME . "_result";
        echo PHP_EOL;
        echo "\n 조직도 데이타 : " . count($group);
        echo PHP_EOL;
        shell_exec($str);
    }
}

function sync_user_data($user_mapping_list)
{
    unset($group);
    if (!empty(_USER_DATA_JSON)) {
        $user_data = file_get_contents(_USER_DATA_JSON);
        $type = 'json';
    } else {
        $user_data = file_get_contents(_USER_DATA_CSV);
        $type = 'csv';
    }
    //sync 될 유저 리스트
    $list_arr = get_list_arr($user_data, $type);

    //기존 db유저 리스트
    $user_list = get_user_list(_DBS_PATH);
    $sosok_list = get_sosok_list(_DBS_PATH);

    unset($member);
    $sync_start = TRUE;
    $error_list = [];
    $column_list=array_flip(array_filter($user_mapping_list, 'myFilter'));

    foreach ($list_arr as $key => $user_data) {
        $user_data =(array)$user_data;
        unset($put_data);
        unset($arr);
        $sabeon = $user_data[$user_mapping_list['sabeon']];

        // 예외 그룹네임 처리
        if (in_array($user_data['groupname'], _EXCEPT_GROUPNAME)) {
            continue;
        }
        //컬럼에 대한 예외처리
        foreach ($user_data as $column => $value) {
            $chk_column = $column_list[$column];
            if(empty($user_data[$column])){
                $error_list[$chk_column.'_ERROR'][] = $user_data;
                if(_IGNORE_EXCEPT_USER_DATA == FALSE){
                    break;
                }
            }

            if(in_array($chk_column,['hp','tel'])){
                $value = str_replace(" ", "-", $value);
                if (chk_phone_format($value)) {
                    $error_list[$chk_column. '_ERROR'][] = $user_data;
                    $value = "";
                }
            }else if(in_array($chk_column,['email'])){
                $email = $user_list[$sabeon]['id'] . "@" . _DOMAIN;
                $value = str_replace(" ", "", $value);
                //자사 이메일인 경우, 이메일 형식 아닐경우 예외 처리
                if (!chk_email_format($value) || $value == $email ) {
                    $error_list['same_mailplug_mail'][] = $user_data;
                    $value = "";
                }
            }else if(in_array($chk_column,['sosok', 'sosokname'])){
                if (!in_array($user_data[$column], array_column($sosok_list, $chk_column))) {
                    $error_list['move_nososok'][] = $user_data;
                    unset($user_data[$user_mapping_list['sosokname']]);
                    unset($user_data[$user_mapping_list['sosok']]);
                }

                if($chk_column == 'sosok' && !empty($user_data[$column])){
                    $value = code_conv($value);
                }
            }
            $put_data[$chk_column] = $value;
        }

        //새로 갱신 필요한 인원 추출
        if (need_to_update($put_data, $user_list, $sabeon)) {
            $put_data['id'] = $user_list[$sabeon]['id'];
            $member[] = $put_data;
        }
    }

    //예외상황 처리방법
    if( !empty($error_list) && _IGNORE_EXCEPT_USER_DATA == FALSE ){
        $sync_start = FALSE;
    }

    file_put_contents('./error_list.txt', print_r($error_list, true));
    $result_list['user_count'] = count($member);
    $result_list['sosok_list'] = array_values(array_unique(array_column($member,'sosokname')));
    $result_list['group_list'] = array_values(array_unique(array_column($member,'groupname')));
    file_put_contents('./result_list.txt', print_r($result_list, true));

    //실제 api전송
    if ($member && $sync_start) {
        // 소속 업데이트
        $dbconn = new SQLite3(_DBS_PATH);
        $dbconn->busyTimeout(30000);

        foreach ($member as $user_info) {
            if ($user_info['sosok']) {
                $query = "update accounts set or_id='" . $user_info['sosok'] . "'where ac_userid = '" . $user_info['id'] . "' ";
                $dbconn->exec($query);
            }
        }
        $dbconn->close();

        file_put_contents(_SAVE_PATH_YM . "/" . _DOMAIN . "_member_" . _FILE_TIME, json_encode($member), FILE_APPEND);
        @unlink(_SAVE_PATH . "/" . _DOMAIN . "_member");
        shell_exec("ln -s " . _SAVE_PATH_YM . "/" . _DOMAIN . "_member_" . _FILE_TIME . " " . _SAVE_PATH . "/" . _DOMAIN . "_member");
        $base64_domain = base64_encode("mbox_host=" . _DOMAIN . "&file_name=" . _SAVE_PATH_YM . "/" . _DOMAIN . "_member_" . _FILE_TIME);
        echo $str = "php /usr/local/plug/html/index.php mail_api member index {$base64_domain} > " . _SAVE_PATH_YM . "/" . _DOMAIN . "_member_" . _FILE_TIME . "_result &";
        echo PHP_EOL;
        echo "\n 회원 데이타 : " . count($member);
        echo PHP_EOL;
        shell_exec($str);
    }
}

// 사용자 가져오기
function get_user_list($dbsfile)
{
    $arr = array();
    if (!file_exists($dbsfile)) {
        return $arr;
    }
    $dbconn = new SQLite3($dbsfile);
    $dbconn->busyTimeout(30000);
    $sql = "
        SELECT ac.ac_id AS seq
        , ac.ac_userid AS id
        , ac.ac_name AS name
        , ai.ai_employee_number AS sabeon
        , ai.ai_gr_name AS groupname
        , ac.ac_active AS active
        , ac.ac_u_priv AS type
        , ai.ai_email AS email
        , ac.or_id AS sosok
        , CASE WHEN ai.ai_or_name='__NOSOSOK__' THEN '소속없음' WHEN ai.ai_or_name is null THEN '소속없음' ELSE ai.ai_or_name END AS or_name
        , ai.ai_ps_name AS gradename
        , ai.ai_join_type AS join_type
        , ai.ai_hp AS hp
        FROM accounts AS ac LEFT JOIN accounts_info AS ai ON (ac.ac_id = ai.ac_id)
        WHERE ac.ac_userid != 'postmaster' and  ac.ac_active IN ('Y','H','G')
        ";
    $userQuery = $dbconn->query($sql);
    if ($userQuery) {
        while ($data = $userQuery->fetchArray(SQLITE3_ASSOC)) {
            if ($data['sabeon'] != "") {
                $arr[$data['sabeon']] = $data;
            }
        }
    }
    $dbconn->close();
    return $arr;
}

// 전화번호 형식체크
function chk_phone_format($hp)
{
    foreach (array($hp) as $num) {
        if ($num != "") {
            if (preg_match("/^[-?0-9+]*$/", $num)) {
                return false;
            } else {
                return true;
            }
        }
    }
}

// 이메일 형식체크
function chk_email_format($user_email)
{
    if (!trim($user_email))
        return false;
    if (strlen($user_email) > 0) {
        $pattern = '/(?!^\.)(?![\w-.]*\.@)(?![\w-.@]+\.{2}[\w-.]+)(?![\w-.]+@[\w-.]+\.[0-9]+$)(?![\w-.]+@-[\w-.]+)(?![\w-.]+@[\w-.]+(\.-|-\.)[\w-.]+)^[\w-.]{1,64}@[\w-.]{1,255}$/i';

        if (preg_match($pattern, $user_email)) {
            return false;
        } else {
            return true;
        }
    }
}


function code_conv($code)
{
    $key_map = array(
            "A" => "11",
            "B" => "12",
            "C" => "13",
            "D" => "14",
            "E" => "15",
            "F" => "16",
            "G" => "17",
            "H" => "18",
            "I" => "19",
            "J" => "20",
            "K" => "21",
            "L" => "22",
            "M" => "23",
            "N" => "24",
            "O" => "25",
            "P" => "26",
            "Q" => "27",
            "R" => "28",
            "S" => "29",
            "T" => "30",
            "U" => "31",
            "V" => "32",
            "W" => "33",
            "X" => "34",
            "Y" => "35",
            "Z" => "36",
            );

    $arr = str_split($code);
    foreach ($arr as $key => $val) {
        if ($key_map[$val] != '')
            $arr[$key] = $key_map[$val];
    }
    return implode("", $arr);
}

function check_encoding($str, $type = "UTF-8")
{
    //$arrEncode = array("UTF-8", "EUC-KR", "JIS", "SHIFT-JIS", "BIG5", "GB2312");
    $arrEncode = array("CP949", "EUC-KR", "UTF-8", "UHC");
    //$chk =  mb_detect_encoding($str, $arrEncode, true);
    $chk =  mb_detect_encoding($str, 'auto');
    if ((!!$chk) && ($chk != $type)) { // case by 반응있고, check 된 type 다를때
        $new_str = iconv($chk, $type . "//IGNORE", $str);
        if (trim($new_str) == '') return $str;
        else return trim($new_str);
    } else {
        return trim($str);
    }
}

function get_list_arr($data, $type){
    $list_arr=[];
    if ($type == 'json') {
        $data = file_get_contents($data);
        $list_arr = json_decode($data, true);
    } else if($type == 'csv'){
        $data = explode("\n", $data);
        foreach($data as $key => $line){
            unset($tmp);
            $tmp= explode(_DELEMETER, $line);
            foreach($tmp as $val){
                $list_arr[$key][] = trim($val);
            }
        }
    }
    return $list_arr;
}

function need_to_update($put_data, $user_list, $sabeon)
{

    if(_ALL_USER_UPDATE){
        return true;
    }
    $chk = false;
## 위에 선언됨 define("_CHECK_SYNC_COLUMN_LIST", ['name','sosok','gradename','groupname']); // 해당 데이터 변경시 데이터 업데이트(기본 : 이름, 소속, 직급, 그룹)
    foreach(_CHECK_SYNC_COLUMN_LIST as $val ){
        if (!empty($put_data[$val]) && $user_list[$sabeon][$val] != $put_data[$val]) {
            $chk = true;
            break;
        }
    }
    return $chk;
}

// 소속 리스트 가져오기
function get_sosok_list($dbsfile){
    $arr = array();
    if(!file_exists($dbsfile)) {
        return $arr;
    }
    $dbconn = new SQLite3($dbsfile);
    $dbconn->busyTimeout(30000);
    $sql = "
        SELECT seq as sosok, sosokname FROM sosok_new
        ";
    $groupQuery = $dbconn->query($sql);
    if($groupQuery)
    {
        while($data = $groupQuery->fetchArray(SQLITE3_ASSOC)){
            if($data['sosokname'] != "")
            {
                $arr[] = $data;
            }
        }
    }
    $dbconn->close();
    return $arr;
}

function myFilter($var){
  return ($var !== NULL && $var !== FALSE && $var !== '');
}
