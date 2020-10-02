<?php
	define("CURLDOMENONLINEEDU", "https://test.online.edu.ru");
	define("MAILUSERERRORONLINEEDU", 2);
	
	function curlsend($path,$jsonstr, $reqtype='POST'){
		
		$ch = curl_init();
		//$verbose = fopen(__DIR__.'/_temp.log', 'a');
		curl_setopt_array($ch, array(
		
		CURLOPT_URL => CURLDOMENONLINEEDU.$path,
		CURLOPT_SSLCERT =>__DIR__.'/1020203079016.crt',
		CURLOPT_SSLKEY => __DIR__.'/1020203079016.key',
		CURLOPT_VERBOSE => true,
		//CURLOPT_STDERR => $verbose,
		
		//CURLOPT_SSL_VERIFYHOST => 2, //Рекоментует PHP
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_SSL_VERIFYPEER => 1,
		CURLOPT_CUSTOMREQUEST => $reqtype,
		CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
		CURLOPT_POSTFIELDS =>$jsonstr//,
		//CURLOPT_RETURNTRANSFER=> true
		
		));
		
		if( ! $response = curl_exec($ch))
		{
			trigger_error(curl_error($ch));
			mtrace("Ошибка запроса CURL: ");
			
		}
		mtrace("Запрос выполнился");
		//var_dump($response);
		//print_r($ch);
		
		$httpcode=curl_getinfo($ch,CURLINFO_HTTP_CODE);											
		mtrace('HTTP_CODE '.$httpcode);
		$arr_return=array('httpcode'=>$httpcode, 'responce' =>$response);
		
		curl_close($ch);
		return( $arr_return);
		
		
	}
	
	
	function errormail($mailtext='Ошибка')
	{
		GLOBAL $DB;
		$userObj = $DB->get_record("user", ['id' => MAILUSERERRORONLINEEDU]); // ID администратора
		
		email_to_user($userObj, $userObj, 'Важно! от Vodin online.edu.ru', $mailtext, $mailtext, ", ", true);
		
	}
?>
