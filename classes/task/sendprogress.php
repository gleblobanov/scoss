<?php
	// This file is part of Moodle - http://moodle.org/
	//
	// Moodle is free software: you can redistribute it and/or modify
	// it under the terms of the GNU General Public License as published by
	// the Free Software Foundation, either version 3 of the License, or
	// (at your option) any later version.
	//
	// Moodle is distributed in the hope that it will be useful,
	// but WITHOUT ANY WARRANTY; without even the implied warranty of
	// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	// GNU General Public License for more details.
	//
	// You should have received a copy of the GNU General Public License
	// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
	
	/**
		* A scheduled task.
		*
		* @package    tool_transferonlineedu
		* @copyright  2020 Anka
		* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
	*/
	//use context_system;
	
	namespace tool_transferonlineedu\task;
	require_once('onlineedu_lib.php');
	
	defined('MOODLE_INTERNAL') || die();
	/**
		Отправка результатов обучения пользователей в online edu
	*/
	class sendprogress extends \core\task\scheduled_task {
		
		
		public function get_name() 
		{
			return get_string('sendprogress', 'tool_transferonlineedu');
		}
		
		
		public function execute() 
		{
			global $DB,$CFG;
			/* описание в задаче по отправке*/
			/*
				Задача делится на три части: 
				1. отправка оценок по заданиям включает также отправку общего прогресса по курсу
				2. отправка только прогресса. Сделана отдельно, т.к. не всегда оценка по заданиям автоматически генерирует прогресс
				3. отправка сертификата
				
				Примечание. Таблица transfer_onlineedu должна гарантировать, что не будут записываться одинаковые результаты обучения
			*/	
			
			$errormail=false; // скидываем флаг отправки почтового сообщения
			/*
				-- передача оценок
				Внимание особенности
				Не передаются данные из категорий журнала оценок
				По модулям передается процент который расчитывается просто n/max*100 . т.е. никакие спец возможности мудла не анализируются формулы, смещения и т.д.
				
			*/
			mtrace('-- Отправляем текущие результаты -- ');
			
			$sqltransfer="SELECT @I:=@I+1 AS aa, mu.id muid,mu.lastname, maoll.username usiaId,
			mc.id mcid,mcd.value id_course_onlineedu, mcd2.value session_onlineedu,
			mgg.finalgrade progress, mgi2.itemname checkpointName, mgi2.iteminstance rating_id_instance, 
			(mgg2.finalgrade/mgi2.grademax*100) rating, mgg2.finalgrade, mgg2.timemodified rating_time
			
			from mdl_course mc  -- определяем курс
			JOIN mdl_customfield_data mcd ON mcd.instanceid=mc.id -- только курсы зарегиные на онлайнеду
			JOIN mdl_customfield_field mcf ON mcf.id=mcd.fieldid  AND mcf.shortname='id_course_onlineedu' -- идентификатор курса в кастомных полях
			
			JOIN mdl_customfield_data mcd2 ON mcd2.instanceid=mc.id -- определение сессии
			JOIN mdl_customfield_field mcf2 ON mcf2.id=mcd2.fieldid  AND mcf2.shortname='session_onlineedu' -- идентификатор сессии курса в onlineedu
			
			JOIN mdl_customfield_data mcd3 ON mcd3.instanceid=mc.id AND mcd3.value=1-- отправка по которым включена
			JOIN mdl_customfield_field mcf3 ON mcf3.id=mcd3.fieldid  AND mcf3.shortname='transfer_onlineedu' 
			
			JOIN mdl_context mc1 ON mc1.instanceid=mc.id  AND mc1.contextlevel=50 -- только студенты
			JOIN mdl_role_assignments mra ON  mra.contextid=mc1.id AND mra.roleid=5  -- это лишний запрос, т.к. в таблицу могут попавть только студенты. Перестраховка
			JOIN mdl_user mu ON mra.userid=mu.id
			
			JOIN transfer_onlineedu tole ON tole.userid=mu.id AND tole.act='enrol' AND tole.sessionId=mcd2.value -- только те кого уже отправили на online edu под текущей сессией
			JOIN mdl_auth_oauth2_linked_login maoll ON maoll.userid=mu.id AND maoll.issuerid=2 -- Важно! идентификатор oauth
			
			JOIN mdl_grade_items mgi ON mgi.courseid=mc.id AND mgi.itemtype='course' -- оценка за курс
			join mdl_grade_grades mgg ON mgg.itemid=mgi.id AND mgg.userid=mu.id
			JOIN mdl_grade_items mgi2 ON mgi2.courseid=mc.id AND mgi2.itemtype='mod' -- оценка за элемент
			join mdl_grade_grades mgg2 ON mgg2.itemid=mgi2.id AND mgg2.userid=mu.id
			-- ищем оценки, которые не были еще отправлены
			LEFT join transfer_onlineedu tole2 ON 
			tole2.userid=mu.id 
			AND tole2.rating_time=mgg2.timemodified 
			AND tole2.rating_id_instance=mgi2.iteminstance -- ищем только новье
			AND tole2.courseid=mc.id
			AND tole2.act='checkpoint'
			
			JOIN (SELECT @I := 0) aa -- для нумерации строк требование мудл
			
			WHERE mgg2.finalgrade IS NOT NULL
			AND tole2.id IS NULL
			-- AND mu.id IN(496,2553, 4575)
			";
			
			try{
				$result=$DB->get_records_sql($sqltransfer);
				mtrace("Чекпоинт Запрос к базе данных прошел успешно");	
				//var_dump($result); exit();
				foreach($result as $rec)
				{
					mtrace('-- USER -- '.$rec->muid);
					
					$json = array();
					
					// $datestr=date( 'c', strtotime($rec->rating_time));
					$datestr=date( 'c', $rec->rating_time);
					//$datestr='2020-09-11T22:55:03+0300';
					
					$json= array(
					"courseId"=>$rec->id_course_onlineedu,
					"sessionId"=>$rec->session_onlineedu,
					"usiaId"=>$rec->usiaid,
					"date"=>$datestr,
					"rating"=>$rec->rating,
					"checkpointName"=>$rec->checkpointname,
					"checkpointId"=>$rec->rating_id_instance,
					"progress"=>$rec->progress
					);
					
					$jsonstr= json_encode($json);
					mtrace($jsonstr);
					
					
					$curlresp=curlsend('/api/v1/course/results/add',$jsonstr, 'POST');
					if($curlresp['httpcode']!=201){
						//mtrace('Выполнено с ошибкой HTTP_CODE'.$response);
						mtrace('Выполнено с ошибкой HTTP_CODE ', $curlresp['httpcode']);
						$errormail=true;
						continue;	// Переходим к следующему юзеру
						
					}
					// Ошибки нет, проводим запись в таблицу логов
					else{
						
						mtrace('Curl Успешно');
						
						$sqlinsert="INSERT INTO transfer_onlineedu
						(userid, user_scos,	act, date_transfer, courseid, course_scos_id,	sessionId, 
						rating, progress, 	rating_time, rating_id_instance, checkpointname)
						
						VALUES (:vuserid,	:vuser_scos,  'checkpoint',	  now(),  :vcourseid,  :vcourse_scos_id,  :vsessionid,
						:rating, :progress,  :rating_time, :rating_id_instance, :checkpointname	)";
						try {
							$insertarray=array(
							'vuserid'=>$rec->muid,
							'vuser_scos'=>$rec->usiaid,
							
							'vcourseid'=>$rec->mcid,
							'vcourse_scos_id'=>$rec->id_course_onlineedu,
							'vsessionid'=>$rec->session_onlineedu,
							
							'rating'=>$rec->rating, 
							'progress'=>$rec->progress, 
							'rating_time'=>$rec->rating_time, 
							'rating_id_instance'=>$rec->rating_id_instance, 
							'checkpointname'=>$rec->checkpointname
							
							);
							$insertResult=$DB->execute($sqlinsert, $insertarray);	
							
							mtrace("Запись в базу успешно ");	
							
							} catch(\Exception $e) {
							mtrace('Ошибка записи в базу данных : '/*.$e*/);
							// var_dump($insertarray);
							$errormail=true;
							
							continue;	// Переходим к следующему юзеру
							
						}
					}
					
				}
			}
			
			catch (\Exception $ex) 
			{			
				mtrace("Ошибка выполнения запроса к базе данных: ".$ex);
				errormail('Ошибка выполнения запроса к БД отправки результатов пользователей');
				$errormail=true;
			}
			
			
			// -----  2. отправка только прогресса.---------------------------------------------------------------
			
			mtrace('-- Отправляем только прогресс -- ');
			$sqltransfer="SELECT @I:=@I+1 AS aa, mu.id muid,mu.lastname, maoll.username usiaId,
			mc.id mcid,mcd.value id_course_onlineedu, mcd2.value session_onlineedu,
			mgg.finalgrade progress,  mgg.timemodified
			
			from mdl_course mc  -- определяем курс
			JOIN mdl_customfield_data mcd ON mcd.instanceid=mc.id -- только курсы зарегиные на онлайнеду
			JOIN mdl_customfield_field mcf ON mcf.id=mcd.fieldid  AND mcf.shortname='id_course_onlineedu' -- идентификатор курса в кастомных полях
			
			JOIN mdl_customfield_data mcd2 ON mcd2.instanceid=mc.id -- определение сессии
			JOIN mdl_customfield_field mcf2 ON mcf2.id=mcd2.fieldid  AND mcf2.shortname='session_onlineedu' -- идентификатор сессии курса в onlineedu
			
			JOIN mdl_customfield_data mcd3 ON mcd3.instanceid=mc.id AND mcd3.value=1-- отправка по которым включена
			JOIN mdl_customfield_field mcf3 ON mcf3.id=mcd3.fieldid  AND mcf3.shortname='transfer_onlineedu' 
			
			JOIN mdl_context mc1 ON mc1.instanceid=mc.id  AND mc1.contextlevel=50 -- только студенты
			JOIN mdl_role_assignments mra ON  mra.contextid=mc1.id AND mra.roleid=5  -- это лишний запрос, т.к. в таблицу могут попавть только студенты. Перестраховка
			JOIN mdl_user mu ON mra.userid=mu.id
			
			JOIN transfer_onlineedu tole ON tole.userid=mu.id AND tole.act='enrol' AND tole.sessionId=mcd2.value -- только те кого уже отправили на online edu под текущей сессией
			JOIN mdl_auth_oauth2_linked_login maoll ON maoll.userid=mu.id AND maoll.issuerid=2 -- Важно! идентификатор oauth
			
			JOIN mdl_grade_items mgi ON mgi.courseid=mc.id AND mgi.itemtype='course' -- оценка за курс
			join mdl_grade_grades mgg ON mgg.itemid=mgi.id AND mgg.userid=mu.id
			
			-- ищем оценки, которые не были еще отправлены
			LEFT join transfer_onlineedu tole2 ON 
			tole2.userid=mu.id 
			AND tole2.rating_time=mgg.timemodified 
			AND tole2.courseid=mc.id
			AND tole2.act='progress'
			
			JOIN (SELECT @I := 0) aa -- для нумерации строк требование мудл
			
			WHERE mgg.finalgrade IS NOT NULL
			AND tole2.id IS NULL
			-- AND mu.id IN(496,2553, 4575)
			";
			
			try{
				$result=$DB->get_records_sql($sqltransfer);
				mtrace("Прогресс Запрос к базе данных прошел успешно");	
				//var_dump($result); exit();
				foreach($result as $rec)
				{
					mtrace('-- USER -- '.$rec->muid);
					
					$json = array();
					
					$json= array(
					"courseId"=>$rec->id_course_onlineedu,
					"sessionId"=>$rec->session_onlineedu,
					"usiaId"=>$rec->usiaid,
					"progress"=>$rec->progress
					);
					
					$jsonstr= json_encode($json);
					mtrace($jsonstr);
					
					
					$curlresp=curlsend('/api/v1/course/results/progress/add',$jsonstr, 'POST');
					if($curlresp['httpcode']!=201){
						//mtrace('Выполнено с ошибкой HTTP_CODE'.$response);
						mtrace('Выполнено с ошибкой HTTP_CODE ', $curlresp['httpcode']);
						$errormail=true;
						continue;	// Переходим к следующему юзеру
						
					}
					// Ошибки нет, проводим запись в таблицу логов
					else{
						
						mtrace('Curl Успешно');
						
						$sqlinsert="INSERT INTO transfer_onlineedu
						(userid, user_scos,	act, date_transfer, courseid, course_scos_id,	sessionId, 	progress, rating_time)
						
						VALUES (:vuserid,	:vuser_scos,  'progress',  now(),  :vcourseid,  :vcourse_scos_id,  :vsessionid,
						:progress, :ratingtime)";
						try {
							$insertarray=array(
							'vuserid'=>$rec->muid,
							'vuser_scos'=>$rec->usiaid,
							'vcourseid'=>$rec->mcid,
							'vcourse_scos_id'=>$rec->id_course_onlineedu,
							'vsessionid'=>$rec->session_onlineedu,
							'progress'=>$rec->progress, 
							'ratingtime'=>$rec->timemodified
							);
							$insertResult=$DB->execute($sqlinsert, $insertarray);	
							
							mtrace("Запись в базу успешно ");	
							
							} catch(\Exception $e) {
							mtrace('Ошибка записи в базу данных : '.$e);
							// var_dump($insertarray);
							$errormail=true;
							
							continue;	// Переходим к следующему юзеру
							
						}
					}
					//break;
				}
			}
			
			catch (\Exception $ex) 
			{			
				mtrace("Ошибка выполнения запроса к базе данных: ".$ex);
				errormail('Ошибка выполнения запроса к БД отправки прогресса пользователей');
				$errormail=true;
				
			}
			
			
			// -----  3. отправка СЕРТИФИКАТА ---------------------------------------------------------------
			
			mtrace('-- Отправляем сертификат -- ');
			$sqlcertif="SELECT @I:=@I+1 AS aa, mu.id muid,mu.lastname, mu.firstname, mu.middlename, maoll.username usiaId,
			mc.id mcid,mcd.value id_course_onlineedu, mcd2.value session_onlineedu,
			mci.code, mci.timecreated, mct.id certid, mct.name certname, mct.contextid certcontext
			
			from mdl_course mc  -- определяем курс
			JOIN mdl_customfield_data mcd ON mcd.instanceid=mc.id -- только курсы зарегиные на онлайнеду
			JOIN mdl_customfield_field mcf ON mcf.id=mcd.fieldid  AND mcf.shortname='id_course_onlineedu' -- идентификатор курса в кастомных полях
			
			JOIN mdl_customfield_data mcd2 ON mcd2.instanceid=mc.id -- определение сессии
			JOIN mdl_customfield_field mcf2 ON mcf2.id=mcd2.fieldid  AND mcf2.shortname='session_onlineedu' -- идентификатор сессии курса в onlineedu
			
			JOIN mdl_customfield_data mcd3 ON mcd3.instanceid=mc.id AND mcd3.value=1-- отправка по которым включена
			JOIN mdl_customfield_field mcf3 ON mcf3.id=mcd3.fieldid  AND mcf3.shortname='transfer_onlineedu' 
			
			JOIN mdl_context mc1 ON mc1.instanceid=mc.id  AND mc1.contextlevel=50 -- только студенты
			JOIN mdl_role_assignments mra ON  mra.contextid=mc1.id AND mra.roleid=5  -- это лишний запрос, т.к. в таблицу могут попавть только студенты. Перестраховка
			JOIN mdl_user mu ON mra.userid=mu.id
			
			JOIN transfer_onlineedu tole ON tole.userid=mu.id AND tole.act='enrol' AND tole.sessionId=mcd2.value -- только те кого уже отправили на online edu под текущей сессией
			JOIN mdl_auth_oauth2_linked_login maoll ON maoll.userid=mu.id AND maoll.issuerid=(SELECT moi.id FROM mdl_oauth2_issuer moi WHERE moi.baseurl like '%online.edu%') -- Важно! идентификатор oauth
			
			JOIN mdl_customcert_issues mci ON mci.userid=mu.id
			join mdl_customcert mc2 ON mc2.id=mci.customcertid AND mc2.course=mc.id
			JOIN mdl_customcert_templates mct ON mct.id=mc2.templateid 
			
			-- ищем оценки, которые не были еще отправлены
			LEFT join transfer_onlineedu tole2 ON 
			tole2.userid=mu.id 
			AND tole2.rating_time=mci.timecreated 
			AND tole2.courseid=mc.id
			AND tole2.act='certificate'
			
			JOIN (SELECT @I := 0) aa -- для нумерации строк требование мудл
			
			WHERE tole2.id IS NULL
			-- AND mu.id IN(496,2553, 4575)
			";
			
			try{
				$result=$DB->get_records_sql($sqlcertif);
				mtrace("Сертификат Запрос к базе данных прошел успешно");	
				//var_dump($result); exit();
				foreach($result as $rec)
				{
					mtrace('-- USER -- '.$rec->muid);
					
					$json = array();
					$datestr=date( 'Y-m-d',$rec->timecreated);
					
					$json= array(
					"certNumber"=>$rec->code,
					"date"=> $datestr,
					"studentName"=>$rec->firstname,
					"studentSurname"=>$rec->lastname,
					"studentPatronymicName"=>$rec->middlename,
					"courseId"=>$rec->id_course_onlineedu,
					"sessionId"=>$rec->session_onlineedu,
					"usiaId"=>$rec->usiaid,
					"enrollAct"=> 'Приказ 1',
					"enrollDate"=>"2017-10-10",
					"complAct"=>'Приказ 1 out',
					"complDate"=>"2017-10-10",
					);
					
					$jsonstr= json_encode($json);
					mtrace($jsonstr);
					
					//  Получаем PDF
					// Now, get the PDF.
					$tempdir = make_temp_directory('attachment');
					if (!$tempdir) {
						mtrace('не удалось создать временную директорию');
						return;
					}
					
					mtrace('Начинаем делать PDF');
					$template = new \stdClass();
					$template->id = $rec->certid;
					$template->name = $rec->certname;
					$template->contextid = $rec->certcontext;
					$template = new \mod_customcert\template($template);
					$filecontents = $template->generate_pdf(false, $rec->muid, true);
					
					//$filename = 'Certificate.pdf';
					
					// Create the file we will be sending.
					$tempfilepdf = $tempdir . '/' . md5(microtime() . $rec->muid) . '.pdf';
					$tempfilejson = $tempdir . '/' . md5(microtime() . $rec->muid) . '.json';
					//mtrace($filename);
					mtrace($tempfilepdf);
					file_put_contents($tempfilepdf, $filecontents);
					file_put_contents($tempfilejson, $jsonstr);
					
					// Конец получения PDF
					$curl_file_json = curl_file_create($tempfilejson, 'application/json' , 'Certificate');
					$curl_file_pdf = curl_file_create($tempfilepdf, 'application/pdf' , 'Certificate');
					$fields=array('certDescription'=>$curl_file_json,'eduDoc'=>$curl_file_pdf);
					
					$ch = curl_init();
					//$verbose = fopen(__DIR__.'/_temp.log', 'a');
					curl_setopt_array($ch, array(
					
					CURLOPT_URL => CURLDOMENONLINEEDU."/api/v1/cert/add",
					CURLOPT_SSLCERT =>__DIR__.'/1020203079016.crt',
					CURLOPT_SSLKEY => __DIR__.'/1020203079016.key',
					CURLOPT_VERBOSE => true,
					//CURLOPT_STDERR => $verbose,
					
					//CURLOPT_SSL_VERIFYHOST => 2, //Рекоментует PHP
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_SSL_VERIFYPEER => 1,
					CURLOPT_CUSTOMREQUEST => 'POST',
					CURLOPT_RETURNTRANSFER =>true,
					CURLOPT_MAXFILESIZE => 5000000,
					CURLOPT_HTTPHEADER => array("Content-Type: multipart/form-data"),//'Content-Length: 4000000'),// . strlen($data_string)),
					//'Content-Length: '. 4000000 /*.  strlen($fields)*/),
					CURLOPT_POSTFIELDS =>$fields,
					//CURLOPT_RETURNTRANSFER=> true
					
					));
					
					mtrace("Начинаем выполнение курла");
					if( ! $response = curl_exec($ch))
					{
						//trigger_error(curl_error($ch));
						mtrace("Ошибка запроса CURL: ");
					}
					mtrace("Запрос выполнился");
					
					// var_dump($response);
					$json = json_decode($response, true);
					// mtrace($json['data']);
					
					$httpcode=curl_getinfo($ch,CURLINFO_HTTP_CODE);											
					mtrace('HTTP_CODE '.$httpcode);
					
					curl_close($ch);
					
					if($httpcode!=201){
						//mtrace('Выполнено с ошибкой HTTP_CODE'.$response);
						mtrace('Выполнено с ошибкой HTTP_CODE ', $curlresp['httpcode']);
						$errormail=true;
						continue;	// Переходим к следующему юзеру
						
					}
					
					else{
					
						// Ошибки нет, проводим запись в таблицу логов
						mtrace('Curl Успешно');
						
						$sqlinsertcertif="INSERT INTO transfer_onlineedu
						(userid, user_scos,	act, date_transfer, courseid, course_scos_id,
							sessionId, 	progress, rating_time, rating_id_instance)
		

						
						VALUES (:vuserid,	:vuser_scos,  'certificate',  now(),  :vcourseid,  :vcourse_scos_id,
								:vsessionid, :progress, :ratingtime, :rating_id_instance)";
						try {
							$insertarray=array(
							'vuserid'=>$rec->muid,
							'vuser_scos'=>$rec->usiaid,
							'vcourseid'=>$rec->mcid,
							'vcourse_scos_id'=>$rec->id_course_onlineedu,
							'vsessionid'=>$rec->session_onlineedu,
							'progress'=>$rec->code, 
							'ratingtime'=>$rec->timecreated,
							'rating_id_instance'=>$json['data']
							);
							$insertResult=$DB->execute($sqlinsertcertif, $insertarray);	
							
							mtrace("Запись в базу успешно ");	
							
							} catch(\Exception $e) {
							mtrace('Ошибка записи в базу данных : '.$e);
							// var_dump($insertarray);
							$errormail=true;
							
							continue;	// Переходим к следующему юзеру
							
						}
					}
					
				}
			}
			
			catch (\Exception $ex) 
			{			
				mtrace("Сертификат Ошибка выполнения запроса к базе данных: ".$ex);		
				$errormail=true;
			}
			
			
			
			//  отсылаем себе сообщение на почту
			if ($errormail){
				errormail('Ошибка отправки результатов пользователей');
				
				
			}
			
		}
		
	}
