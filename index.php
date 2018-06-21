<?php
header('Content-type: text/html; charset=UTF-8');
include_once "errorCode.php";
include_once "DB.class.php";

/**
 * SHA1 class
 *
 * 计算公众平台的消息签名接口.
 */
class SHA1
{
	public $_conn; // 数据库连接
	public $_token = 'fu200811';
//	public $_appid = 'wx372f1b95135960d5'; //用户唯一凭证
//	public $_secret = '1bec6eb6bc06dfcbcb3afd37d4aa5f8e';//用户唯一凭证密钥
	public $_appid = 'wxd1cc76f79fa681cf'; //用户唯一凭证
	public $_secret = '9436c94ed8799e7e2196cb804a497596';//用户唯一凭证密钥

	public function __construct()
	{
		$this->_conn = DB::getInstance();
	}

	/**
	 * 获取凭证码
	 * @return string access_token
	 * @author fuliang
	 * @date 2018-01-21
	 */
	public function initGetAccessToken()
	{
		$accessToken = '';
		$sql = "SELECT * FROM `access_token` WHERE deleted = 0";
		$row = $this->_conn->getRow($sql);
		if ($row) {
			// 过期时间，当小于0时表示access_token已过期；
			$isOverdue = ($row['create_time'] + $row['expires_in']) - time();
			if ($isOverdue <= 0) {
				$result = $this->createAccessToken();
				if ($result['success']) {
					$accessToken = $result['access_token'];
				}
			} else {
				$accessToken = 	$row['access_token'];
			}
		} else {
			$result = $this->createAccessToken();
			if ($result['success']) {
				$accessToken = $result['access_token'];
			}
		}

		return $accessToken;
	}
	/**
	 * 用SHA1算法生成安全签名
	 * @param string $token 票据
	 * @param string $timestamp 时间戳
	 * @param string $nonce 随机字符串
	 * @param string $encrypt 密文消息
	 */
	public function getSHA1($token, $timestamp, $nonce, $encrypt)
	{
		//排序
		try {
			$array = array($token, $timestamp, $nonce, $encrypt);
			sort($array, SORT_STRING);
			$str = implode($array);
			return array(ErrorCode::$OK, sha1($str));
		} catch (Exception $e) {
			return array(ErrorCode::$ComputeSignatureError, null);
		}
	}

	/**
	 * CURL模式调用
	 * @author tanbj
	 * @date 2017-04-29
	 * @param string  $url    url请求地址
	 * @param array   $params 数组参数，key => value形式
	 * @param boolean $isPost 是否是POST请求形式，默认为true
	 * @return array
	 */
	public function curl($url, array $params, $isPost = true)
	{
		// 初始化参数
		$curlResult = array();

		$curl = curl_init();
		if ($isPost) {
			// 处理POST请求
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
		} else {
			// 处理GET请求
			$buildUrl = http_build_query($params);
			if (strpos($url, '?') === false) {
				$getUrl = $url . '?' . $buildUrl;
			} else {
				$getUrl = $url . '&' . $buildUrl;
			}

			curl_setopt($curl, CURLOPT_URL, $getUrl);
			curl_setopt($curl, CURLOPT_POST, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_HEADER, 0);
		}
		curl_setopt($curl, CURLOPT_TIMEOUT, 0);

		$curlOutput = curl_exec($curl);
		$curlError  = curl_errno($curl);
		curl_close($curl);

		// 处理返回参数
		$curlResult = json_decode($curlOutput, true);
		$curlResult['curl_error'] = $curlError;

		return $curlResult;
	}

	/**
	 * 修改接口配置信息的验证签名方法
	 * @author fuliang
	 * @date 2018-01-20
	 */
	public function editConfigValidate()
	{
		$data = $_GET;
		$signature = $data['signature'];
		$timestamp = $data['timestamp'];
		$nonce = $data['nonce'];
		$echoStr = $data['echostr'];
		$result = $this->getSHA1($this->_token,$timestamp,$nonce,'');
		if ($result[1] == $signature) {
			ob_clean();
			echo $echoStr;
		}
	}

	/**
	 * 获取access_token并保存到数据库
	 * @author fuliang
	 * @date 2018-01-20
	 */
	public function createAccessToken()
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$this->_appid.'&secret='.$this->_secret;
		$result = json_decode(file_get_contents($url),true);
		if ($result['access_token']) {
			// 删除过期的凭证记录
			$time = time();
			$isOverdue = $time - 7200;
			$sql = "UPDATE `access_token` SET deleted = 1 WHERE create_time <= {$isOverdue}";
			$this->_conn->query($sql);
			// 插入新的凭证码
			$sql = "INSERT INTO `access_token` VALUES (null,'{$result['access_token']}','{$result['expires_in']}','{$time}',0)";
			$res = $this->_conn->query($sql);
			if ($res) {
				$returnArr = ['success' => true, 'access_token' => $result['access_token'], 'message' => '获取并保存token成功！'];
			} else {
				$returnArr = ['success' => false, 'access_token' => $result['access_token'], 'message' => '保存token失败！'];
			}
		} else {
			$returnArr = ['success' => false, 'access_token' => $result['access_token'], 'message' => '获取token失败！'];
		}

		return $returnArr;
	}

	/**
	 * 创建菜单
	 * @param $accessToken 凭证码
	 * @return array
	 */
	public function createMenu($accessToken)
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/menu/create?access_token='.$accessToken;
		$postStr ='{
			"button":[
			{
				"type":"click",
				"name":"今日天气",
				"key":"V1001"
			},
			{
				"type":"click",
				"name":"讲个笑话",
				"key":"V1002"
			},
			{
				"type":"click",
				"name":"点个赞",
				"key":"V1003"
			}]
		}';
		$result = $this->curl($url,$postStr);

		return $result;
	}

	/**
	 * 获取菜单
	 * @param $accessToken 凭证码
	 * @return mixed
	 */
	public function getMenu($accessToken)
	{
		$url = 'https://api.weixin.qq.com/cgi-bin/menu/get?access_token='.$accessToken;
		$result = json_decode(file_get_contents($url),true);

		return $result;
	}

	/**
	 * 将xml数据包转换为数组返回
	 * @param $xmlData xml数据包
	 * @return array
	 */
	public function analysisXmlData($xmlData)
	{
		// 解析xml数据包
		$xml = simplexml_load_string($xmlData);
		$data = [];
		foreach($xml as $key => $value){
			$data[$key] = (string)$value;
		}

		return $data;
	}

	/**
	 * 根据城市获取近期天气情况
	 * @param string $cityName 城市名称
	 * @param string $type all:返回近一周天气情况 || one返回今天的天气情况
	 * @return string
	 */
	public function getCityWeather($cityName = '成都', $type = 'all')
	{
		$content = '这是一个天气查询功能，请输入一个正确的城市名称（如：成都）。';
		// 获取指定城市天气信息
		$strReplace = str_replace('市', '', $cityName);
		// 根据城市获取近期天气情况API
		$weatherJson = file_get_contents('http://www.sojson.com/open/api/weather/json.shtml?city='.$strReplace);
		$weatherArr = json_decode($weatherJson, true);
		if (!empty($weatherArr) && is_array($weatherArr) && $weatherArr['status'] == 200) {
			switch ($type) {
				case 'all':
					$content = $weatherArr['city'].'近期天气状况：';
					$yesterday = $weatherArr['data']['yesterday'];
					$forecast = $weatherArr['data']['forecast'];
					$content .= $yesterday['date'].'：'.$yesterday['type'].'，'.$yesterday['low'].'，'.$yesterday['high'].'，风力：'.$yesterday['fl'].'。';
					foreach ($forecast as $weather) {
						$content .= $weather['date'].'：'.$weather['type'].'，'.$weather['low'].'，'.$weather['high'].'，风力：'.$weather['fl'].'。';
					}
				break;
				case 'one':
					$content = $weatherArr['city'].'现在气温：'.$weatherArr['data']['wendu'].'℃，空气质量：'.$weatherArr['data']['quality'].'，PM2.5指数：'.$weatherArr['data']['pm25'].'，PM10指数：'.$weatherArr['data']['pm10'].'，湿度：'.$weatherArr['data']['shidu'];
			}
		}

		return $content;
	}

	/**
	 * 根据经纬度获取城市名称
	 * @param $latitude 经度
	 * @param $longitude 纬度
	 * @return array
	 */
	public function getAddressBy($latitude, $longitude)
	{
		$returnArr = ['success' => false, 'city_name' => '', 'message' => '获取城市名称失败！'];
		// 百度地图API，根据经纬度返回地理位置信息
		$addressJson = file_get_contents('http://api.map.baidu.com/geocoder?location='.$latitude.','.$longitude.'&output=json');
		$addressArr = json_decode($addressJson, true);
		if ($addressArr['status'] == 'OK') {
			// 城市名称
			$cityName = $addressArr['result']['addressComponent']['city'];
			if (!empty($cityName)) {
				$returnArr = ['success' => true, 'city_name' => $cityName, 'message' => '获取城市名称成功！'];
			}
		}

		return $returnArr;
	}

	/**
	 * 保存微信用户位置信息
	 * @param $data 用户位置信息数组
	 * @return array
	 */
	public function saveUserAddressInfo($data)
	{
		$sql = "INSERT INTO `user_address` VALUES (null,'{$data['ToUserName']}','{$data['FromUserName']}','{$data['Latitude']}','{$data['Longitude']}','{$data['Precision']}','{$data['CreateTime']}')";
		$this->_conn->query($sql);
	}

	/**
	 * 根据微信用户名获取用户地理位置
	 * @param $username 用户名
	 * @return array
	 */
	public function getUserAddressByUsername($username)
	{
		$returnArr = ['success' => false, 'data' => [], 'message' => '未获取到用户位置信息！'];
		$sql = "SELECT `latitude`, `longitude` FROM `user_address` WHERE `from_user_name` = '{$username}' ORDER BY `create_time` DESC LIMIT 1";
		$row = $this->_conn->getRow($sql);
		if (!empty($row) && is_array($row)) {
			$returnArr = ['success' => true, 'data' => $row, 'message' => '获取用户位置信息成功！'];
		}

		return $returnArr;
	}

	/**
	 * 保存微信用户发送的数据信息
	 * @param $MsgId
	 * @param $ToUserName
	 * @param $FromUserName
	 * @param $CreateTime
	 * @param $MsgType
	 * @param $Content
	 */
	public function saveUserSendMessage($MsgId, $ToUserName, $FromUserName, $CreateTime, $MsgType, $Content)
	{
		// 将用户发送的消息保存到数据库
		$sql = "INSERT INTO `message` VALUES (null,'{$MsgId}','{$ToUserName}','{$FromUserName}','{$CreateTime}','{$MsgType}','{$Content}')";
		$this->_conn->query($sql);
	}

	/**
	 * 获取笑话
	 * @return array
	 */
	public function getJoke()
	{
		$returnArr = ['success' => false, 'content' => ''];
		$appKey = 'fd905ca051a9d1c54442cf5d1eb03dcc'; // 聚合笑话接口appkey
		$page = rand(1,99);
		$url = 'http://v.juhe.cn/joke/content/text.php?key='.$appKey.'&page='.$page.'&pagesize=20';
		$jsonArr = file_get_contents($url);
		$jokeArr = json_decode($jsonArr, true);
		if ($jokeArr['reason'] == 'Success') {
			$content = $jokeArr['result']['data'][rand(0,19)]['content'];
			$returnArr = ['success' => true, 'content' => $content];
		}

		return $returnArr;
	}

	public function sendMessage()
	{
		$accessToken = $this->initGetAccessToken();
		$url = 'https://api.weixin.qq.com/cgi-bin/media/uploadnews?access_token='.$accessToken;
		$postStr = '{
						"articles": [
							{
								"thumb_media_id":"EfU9S6CWpjyMcI72TQZpfcEjEPcI6tGKg6pHqUlr9JXr5faem1SmovHDqNHaS0Bs",
								"author":"fuliang",
								"title":"test-fuliang",
								"content_source_url":"www.qq.com",
								"content":"这是一个图文消息页面的内容",
								"digest":"图文消息的描述",
								"show_cover_pic":1
							}
						]
					}';
		$data = json_decode($postStr, true);
		$result = $this->curl($url, $data);

		return $result;
	}
}
$obj = new SHA1();
$obj->editConfigValidate();die;
$postStr = file_get_contents('php://input');
$data = $obj->analysisXmlData($postStr);
// 保存微信用户的位置信息
if ($data['Event'] == 'LOCATION') {
	$obj->saveUserAddressInfo($data);
}
// 将数组打散成key = value形式的变量
extract($data);
$content = '';
$time = time();
if ($MsgType == 'text') {
	// 将用户发送的消息保存到数据库
	$obj->saveUserSendMessage($MsgId, $ToUserName, $FromUserName, $CreateTime, $MsgType, $Content);
	$content = $obj->getCityWeather($Content);
} elseif ($MsgType == 'event') {
	switch ($EventKey) {
		case 'V1001':
			$content = '我暂时无法获取您的位置信息，可能是因为没有获取您位置信息的权限！';
			// 查微信用户所在城市，并返回该城市的天气情况
			$userAddress = $obj->getUserAddressByUsername($FromUserName);
			if ($userAddress['success']) {
				$cityName = $obj->getAddressBy($userAddress['data']['latitude'], $userAddress['data']['longitude']);
				if ($cityName['success']) {
					$content = $obj->getCityWeather($cityName['city_name'], 'one');
				}
			}
			break;
		case 'V1002':
			$content = '我嘴巴都讲干了，您让我休息会儿吧。';
			$result = $obj->getJoke();
			if ($result['success']) {
				$content = $result['content'];
			}
			break;
		case 'V1003':
			$content = '谢谢您的点赞，我会继续努力的[Yeah!]';
			break;
	}
} else {
	$content = '你要干嘛？';
}
$sendArr = '<xml>
				<ToUserName><![CDATA['.$FromUserName.']]></ToUserName>
				<FromUserName><![CDATA['.$ToUserName.']]></FromUserName>
				<CreateTime>'.$time.'</CreateTime>
				<MsgType><![CDATA[text]]></MsgType>
				<Content><![CDATA['.$content.']]></Content>
			</xml>';
ob_clean();

echo $sendArr;