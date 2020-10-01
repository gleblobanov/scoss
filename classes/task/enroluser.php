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
	
	// В этой библиотеке функция выполнения CURL
	require_once('onlineedu_lib.php');
	
	
	defined('MOODLE_INTERNAL') || die();
	/*
		Отправка пользователей на регистрацияю в online edu
		
		Скрипт записи студентов на курс Отправляет информацию кто куда записан
		доп моменты
		1. У курса должно быть доп поля
		id_course_onlineedu с идентификатором курса на сцосе. Так определяется по каким курсам отправлять народ
		session_onlineedu - сессия курса на сцос
		transfer_onlineedu - флаг показывающй отправляется ли информация на сцос
		2. В таблице линк надо точно знать номер oauth (issuerid=2) (сейчас реализовано автоматическое определение)
		3. Есть таблица transfer_onlineedu по которой определяется кто уже был отправлен
		4. Регистрация и отправка результатов происходит по-одному пользователю
		
	*/
	
	
	class enroluser extends \core\task\scheduled_task {
		
		
		public function get_name() 
		{
			return get_string('enroluser', 'tool_transferonlineedu');
		}
		
		/**
			* Do the job.
			* Throw exceptions on errors (the job will be retried).
		*/
		public function execute() 
		{
			global $DB,$CFG;
			
			
			$errormail=false;
			
			// --------------  Начало отписки юзеров. Не используется.	
			
			if(false){
				mtrace('Выполняем отписку студентов');
				$sqlunenrol="-- Скрипт для отписки всех студентов
				SELECT tole.user_scos, tole.course_scos_id, tole.sessionId
				FROM transfer_onlineedu tole
				WHERE tole.act='enrol'";
				$result=$DB->get_records_sql($sqlunenrol);
				foreach($result as $rec)
				{
					$json = array();
					$json= array(
					"courseId"=>$rec->course_scos_id,
					"sessionId"=>$rec->sessionid,
					"usiaId"=>$rec->user_scos,
					);
					
					$jsonstr= json_encode($json);
					mtrace($jsonstr);
					/*	Здесь вызов функции отправки*/
					//curlsend('/api/v1/course/unenroll',$jsonstr, 'POST');
					$curlresp=curlsend('/api/v1/course/unenroll',$jsonstr, 'POST');
					
					if($curlresp['httpcode']!=200){
						//mtrace('Выполнено с ошибкой HTTP_CODE'.$response);
						mtrace('Выполнено с ошибкой HTTP_CODE');
						$errormail=true;
						continue;	// Переходим к следующему юзеру
						
					}
					
					//break;
				}
				exit();
			}
			
			
			// --------------  Конец отписки юзеров	
			
			// -- Зачисление юзеров -- 
			
			$sqlenroluser="SELECT @I:=@I+1 AS aa, mu.id, mu.lastname, maoll.userid, maoll.username,   mc.id mcid,
			DATE_FORMAT( FROM_UNIXTIME(mue.timecreated),'%Y-%m-%dT%T') DATETIME, mcd.value id_course_onlineedu, mcd2.value session_onlineedu
			FROM mdl_user mu
			JOIN mdl_auth_oauth2_linked_login maoll ON maoll.userid=mu.id AND maoll.issuerid=(SELECT moi.id FROM mdl_oauth2_issuer moi WHERE moi.baseurl like '%online.edu%')  -- только те кто зашел с онлайнеду
			
			JOIN mdl_user_enrolments mue ON mue.userid=mu.id -- далее определение курса и только студенты
			join mdl_enrol me ON me.id=mue.enrolid
			JOIN mdl_course mc ON mc.id=me.courseid
			JOIN mdl_context mc1 ON mc1.instanceid=mc.id AND mc1.contextlevel=50
			JOIN mdl_role_assignments mra ON mra.contextid=mc1.id AND mra.roleid=5 AND mra.userid=mu.id
			
			JOIN mdl_customfield_data mcd ON mcd.instanceid=mc.id -- только курсы зарегиные на онлайнеду
			JOIN mdl_customfield_field mcf ON mcf.id=mcd.fieldid  AND mcf.shortname='id_course_onlineedu' -- идентификатор курса в кастомных полях
			
			JOIN mdl_customfield_data mcd2 ON mcd2.instanceid=mc.id -- определение сессии
			JOIN mdl_customfield_field mcf2 ON mcf2.id=mcd2.fieldid  AND mcf2.shortname='session_onlineedu' -- идентификатор сессии курса в onlineedu
			
			JOIN mdl_customfield_data mcd3 ON mcd3.instanceid=mc.id AND mcd3.value=1-- отправка по которым включена
			JOIN mdl_customfield_field mcf3 ON mcf3.id=mcd3.fieldid  AND mcf3.shortname='transfer_onlineedu' 
			
			-- те кто еще не был отправлен в систему ранее/ под той же сессией
			LEFT JOIN transfer_onlineedu tole ON tole.userid=mu.id AND tole.act='enrol' AND tole.sessionId LIKE mcd2.value
			
			JOIN (SELECT @I := 0) aa
			WHERE tole.id IS NULL
			-- AND mu.id IN(496,2553)
			";
			
			
			try{
				$result=$DB->get_records_sql($sqlenroluser);
				mtrace("Запрос к базе данных прошел успешно");	
				foreach($result as $rec)
				{
					mtrace('-- USER ',$rec->userid,' --',$jsonstr);
					
					$json = array();
					
					$datestr=date( 'c', strtotime($rec->datetime));
					//$datestr='2020-09-11T22:55:03+0300';
					
					// Объект с информацией о студенте. 
					$json= array(
					"courseId"=>$rec->id_course_onlineedu,
					"sessionId"=>$rec->session_onlineedu, 
					"usiaId"=>$rec->username,
					"enrollDate"=>$datestr
					);
					
					$jsonstr= json_encode($json);
					
					// Вызов функции выполнения CURL
					$curlresp=curlsend('/api/v1/course/enroll',$jsonstr, 'POST');
					// Есть ошибка отправляем сообщение на почту
					if($curlresp['httpcode']!=201){
						
						mtrace('Выполнено с ошибкой HTTP_CODE ', $curlresp['httpcode']);
						$errormail=true;
						continue;	// Переходим к следующему юзеру
					}
					// Ошибки нет, проводим запись в таблицу логов
					// Если на этапе записи произойдет проблема, то первый запрос вновь отправит даннх пользователей на регистрацию. Должно быть все хорошо
					else{
						// -- сохранять в таблице transfer_onlineedu только после положительного ответа
						//var_dump('Выполняем запрос');
						mtrace('Curl Успешно');
						
						$sqlinsert="INSERT INTO transfer_onlineedu
						( userid, user_scos,  act,  date_transfer,  courseid,  course_scos_id,  sessionid)
						VALUES (:vuserid,	:vuser_scos,  'enrol',  now(),  :vcourseid,	  :vcourse_scos_id,	  :vsessionid)";
						
						try {
							$insertResult=$DB->execute($sqlinsert, array(
							'vuserid'=>$rec->userid,
							'vuser_scos'=>$rec->username,
							'vcourseid'=>$rec->mcid,
							'vcourse_scos_id'=>$rec->id_course_onlineedu,
							'vsessionid'=>$rec->session_onlineedu));	
							
							mtrace('Запись в БД прошла успешно');
							
							
							} catch(\Exception $e) {
							mtrace('Ошибка записи в БД: '.$e);
							$errormail=true;
							continue;	// Переходим к следующему юзеру если ошибка
							
						}
					}
					
				}
			}
			catch (\Exception $ex) // Здесь фокус в обратном слеше
			{						
				mtrace("Ошибка выполнения запроса к базе данных: ".$ex);	
				$errormail=True;
				//exit();
				
				// Это функция наша отправки сообщений внутри СДО. Библиотеки нет в этом плагине
				// \tool_discdorequestprocess_ex_api::SendMessage(22,22,$errMsg,'1');
			}
			
			
			/* Временный внутренний второстепенный скрипт УГНТУ Записывает в поле пользователя инфомацию о регистрации черз online для ограничения доступа к сертификатам*/
			// добавление или  апгрейд 
			$authinsertsql='INSERT INTO mdl_user_info_data
			(userid, fieldid, data, dataformat)
			
			SELECT maoll.userid, muif.id  ,1,0
			FROM mdl_auth_oauth2_linked_login maoll
			JOIN mdl_user_info_field muif ON muif.shortname="auth_onlineedu" 
			WHERE maoll.issuerid=(SELECT moi.id FROM mdl_oauth2_issuer moi WHERE moi.baseurl like "%online.edu%")
			
			ON DUPLICATE KEY UPDATE data=1';
			
			try{
				mtrace('Прописываем поля пользователя auth_onlineedu');
				$result=$DB->execute($authinsertsql);
				mtrace("Запрос к базе данных прошел успешно");	
			}
			catch (\Exception $ex) // Здесь фокус в обратном слеше
			{						
				mtrace("Ошибка выполнения запроса к базе данных: ".$ex);	
			}
			
			// Правильно было бы еще написать скрипт скидывания при разлинковке. Но это суперредкость не хочу париться
			/*Конец скрипта по записи в поле юзера*/
			
			
			/*  отсылаем себе сообщение на почту один раз за весь скрипт. 
			Поэтому если будет проблема с несколькими пользователями, то смотреть в результатах выполнения задачи*/
			if ($errormail){
				errormail('Ошибка отправки пользователей на online edu');
			}
			
		}
		
	}
