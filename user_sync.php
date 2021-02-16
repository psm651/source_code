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
define("_DOMAIN", ""); //도메인
define("_ROOT_ORGAN", ""); // 업체명(조직도 루트)
define("_DELEMETER", ""); // 데이터 구분자

define("_GROUP_DATA_JSON", ""); //조직도 데이터 json
define("_GROUP_DATA_CSV", ""); //조직도 데이터 csv
define("_IGNORE_EXCEPT_GROUP_DATA", ""); // 조직도 예외데이터 처리방법

define("_USER_DATA_JSON", ""); //사용자 데이터 json
define("_USER_DATA_CSV", ""); //사용자 데이터 csv
define("_IGNORE_EXCEPT_USER_DATA", ""); // 사용자 예외데이터 처리방법



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
    'sosok_order'  =>  '', //부서내 사용자 순서
    'hp'  =>  '', //핸드폰
    'tel'  =>  '', //전화
    'email'  =>  '', //email
    'gradename'  =>  '', //직급
    'groupname'  =>  '', //그룹
];

//그룹데이터 동기화
sync_group_data();

//회원 데이타
sync_user_data();

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

function sync_group_data($group_mapping_list)
{
    //조직도 데이타
    unset($group);
    if (!empty(_GROUP_DATA_JSON)) {
        $data_group = file_get_contents(_GROUP_DATA_JSON);
        $list_arr = json_decode($data_group, true);
    } else {
        $data_group = file_get_contents(_GROUP_DATA_CSV);
    }

    $root_code = 0;
    $sync_start = TRUE;
    $key = array_search(_ROOT_ORGAN, array_column($list_arr, 'name'));
    $root_code = $list_arr[$key]['code'];
    $cotrol_depth_level = $list_arr[$key]['depth'];

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

        $column_list=array_keys(array_filter($group_mapping_list));
        $error_list = [];
        foreach ($column_list as $column) {
            if(empty($row[$group_mapping_list[$column]])){
                $error_list[] = $row;
                if(_IGNORE_EXCEPT_GROUP_DATA == FALSE){
                    continue;
                }
            }
            if(in_array($column,['code','parentcode','sortorder'])){
                $put_data[$column] = code_conv($row[$group_mapping_list[$column]]);
            }else if(in_array($column,['code','parentcode','sortorder'])){
                $put_data[$column] = check_encoding($row[$group_mapping_list[$column]]);
            }else if($column == 'depth'){
                $put_data[$column] = ($row[$group_mapping_list[$column]] - $cotrol_depth_level);
            }
        }

        if ($put_data['code']) {
            $group[] = $put_data;
        }
    }
    if( !empty($error_list) && _IGNORE_EXCEPT_GROUP_DATA == FALSE ){
        $sync_start = FALSE;
    }

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

function sync_user_data()
{
    $data_member = file_get_contents(_USER_DATA);
    file_put_contents('./user_list_file', $data_member);
    $list_arr = json_decode($data_member, true);

    $dbs_path = "/home/mail/domaindir/" . _DOMAIN  . "/_DBS/accounts.dbs";
    $user_list = getUserList($dbs_path);

    unset($member);
    foreach ($list_arr as $key => $user_data) {
        unset($put_data);
        unset($arr);
        $sabeon = $user_data['sabeon'];
        $chk = false;
        #$chk = true;

        foreach ($user_data as $column => $value) {
            $put_data[$column] = $value;
        }

        $put_data['hp'] = str_replace(" ", "-", $put_data['hp']);
        $put_data['tel'] = str_replace(" ", "-", $put_data['tel']);
        $put_data['email'] = str_replace(" ", "", trim($put_data['email']));
        $put_data['sosok'] = code_conv($put_data['sosok']);
        $put_data['sosok_order'] = code_conv($put_data['sosok_order']);


        if ($put_data['hp'] != "" && chk_phone_format($put_data['hp'])) {
            $put_data['hp'] = "";
        }
        if ($put_data['tel'] != "" && chk_phone_format($put_data['tel'])) {
            $put_data['tel'] = "";
        }
        if ($put_data['email'] != "" && chk_email_format($put_data["email"])) {
            $put_data['email'] = "";
        }

        //자사 이메일인 경우 예외 처리
        $email = $user_list[$sabeon]['id'] . "@" . _DOMAIN;
        if ($put_data['email'] == $email)
            $put_data['email'] = "";
        //전화 번호 변경시
        // if($user_list[$sabeon]['hp'] != $put_data['hp'])
        // 	$chk = true;
        // if($user_list[$sabeon]['email'] != $put_data['email'])
        // 	$chk = true;

        // 이름이 변경된 경우 업데이트
        if ($put_data['name'] != "" && $user_list[$sabeon]['name'] != $put_data['name'])
            $chk = true;
        // 소속이 변경된 경우 업데이트
        if ($put_data['sosok'] != "" && $user_list[$sabeon]['sosok'] != $put_data['sosok'])
            $chk = true;
        // 직급이 변경된 경우 업데이트
        if ($put_data['gradename'] != "" && $user_list[$sabeon]['title'] != $put_data['gradename'])
            $chk = true;
        // 그룹이 변경된 경우 업데이트
        if ($put_data['groupname'] != "" && $user_list[$sabeon]['gr_name'] != $put_data['groupname'])
            $chk = true;

        if ($chk) {
            $put_data['id'] = $user_list[$sabeon]['id'];
            $member[] = $put_data;
        }
    }

    if ($member) {
        // 소속 업데이트
        $dbconn = new SQLite3($dbs_path);
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
function getUserList($dbsfile)
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
		, ai.ai_gr_name AS gr_name
		, ac.ac_active AS active
		, ac.ac_u_priv AS type
		, ai.ai_email AS email
		, ac.or_id AS sosok
		, CASE WHEN ai.ai_or_name='__NOSOSOK__' THEN '소속없음' WHEN ai.ai_or_name is null THEN '소속없음' ELSE ai.ai_or_name END AS or_name
		, ai.ai_ps_name AS title
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
