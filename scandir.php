<?php
 /**
 * ScanDir - Плагин проверки изменения файлов сайта
 *
 * @version          1.0 (beta)
 * @author           Участники сообщества joomlaforum.ru
 *					 JLend (info@jlend.ru)
 *					 Профиль пользователя: http://joomlaforum.ru/index.php?action=profile;u=190209
 *					 Филипп Сорокин (Philip Sorokin) (philip.sorokin@gmail.com) https://addondev.com 
 *					 Профиль пользователя: http://joomlaforum.ru/index.php?action=profile;u=189546
 * @copyright        (C) 2016 by JLend(http://www.jlend.ru)
 * @license          GNU General Public License version 2 or later; see LICENSE.txt
 * @repositories     https://github.com/jlend/scan_dir
 *					 Топик поддержки расширения:  http://joomlaforum.ru/index.php/topic,323669.msg1620914.html
 **/
 
defined('_JEXEC') or die('Restricted Access');

class plgSystemScanDir extends JPlugin {

	PUBLIC FUNCTION onBeforeRender() {
	
		$app = JFactory::getApplication();
		$jinput = $app->input;
		
		//В админке не работаем
		if($app->isAdmin()) {
			return false;
		}

		// Получаем имя файла из параметров
		$name = $this->params->get('file');
		
		// Получаем E-mail для отчетов из параметров
		$email = $this->params->get('email');
		
		// Получаем интервал времени проверок из параметров
		$time = $this->params->get('time');
		
		// Выбераем режим сканирования по атрибутам или содержанию файла
		$scan = $this->params->get('scan');

		$time_start = microtime(true);
		
		$file_time = filemtime(".".$name);	
		$scan_time = $file_time + ($time * 60);
		$new_time = time();

		if(file_exists(".{$name}") && $scan_time < $new_time) {

			//Закомментирована отладка
//			echo "<html><pre>";

			// Каталог в котором сканируем.
			$scandir = $_SERVER['DOCUMENT_ROOT'];
			
			// Получаем имя сервера если оно отсутствует подставляем "Unknown"
			if(isset($_SERVER['HTTP_HOST']))
			{
				$servername = $_SERVER['HTTP_HOST'];
			}
			else if(isset($_SERVER['SERVER_NAME']))
			{
				$servername = $_SERVER['SERVER_NAME'];
			}
			else
			{
				$servername ="Unknown";
			}
	
			// Файл указаный в настройках для записи хеш-сумм
			$datafilename = ".{$name}";

			date_default_timezone_set("UTC");
			
			/**
			 * Exclude File List - Separate each entry with a semicolon ;
			 * Full filename including path and extension. [CASE INSENSITIVE]
			 */
			$excludeFileList = ".{$name}";

			/**
			 * Exclude Extension List - Separate each entry with a semicolon ;
			 * Only extension type. [CASE INSENSITIVE]
			 * Do not leave trailing semicolon!
			 */
			$excludeExtensionList = "doc;log;bak;xls;zip";

			/**
			 * Exclude directory List - Separate each entry with a semicolon ;
			 * Only relative dir name including trailing dir separator. [CASE INSENSITIVE]
			 * Do not leave trailing semicolon!
			 */
			$excludeDirList = "cache/";
			
			/**
			 * Указываем email из настроек для отправки.
			 */
			$emailAddressToAlert = $email;
			
			/**
			 * Тема приходящего письма сообщения.
			 */
			$emailSubject = "Файлы веб-сервера '$servername' были изменены";

			//Начинаем сканирование и сравнение.
	
			/**
			 * Режим сравнения файлов:
			 *   attributes - по модификации метки времени и размера файла
			 *   content - по содержимому файла
			 */
			$mode = $scan == 1 ? "attributes" : "content";
			
			/**
			 * Данные исключения из сканирования.
			 */
			 
			// Исключенные из сканирования директории
			$offdir = isset($excludeDirList) ? explode(';', strtolower($excludeDirList)) : array();
	 
			// Исключенные из сканирования файлы
			$offfile = isset($excludeFileList) ? explode(';', strtolower($excludeFileList)) : array();
			
			// Исключенные из сканирования расширения файлов
			$offext = isset($excludeExtensionList) ? explode(';', strtolower($excludeExtensionList)) : array();

			//Проверяем, существуют ли ранее сохраненные данные если есть то используем их
			if(substr($scandir, strlen($scandir) - 1) !== DIRECTORY_SEPARATOR)
			{
				$scandir .= DIRECTORY_SEPARATOR;
			}	
			
			$olddata = array();
			
			if(file_exists($scandir . $datafilename))
			{
				$datafile = fopen($scandir . $datafilename, "r");
				
				if($datafile)
				{
					while(($buffer = fgets($datafile)) !== false)
					{
						$line = explode("\t", str_replace("\n", "", $buffer));
						
						$entry = array(
							"namehash" => $line[0],
							"checkhash" => $line[1],
							"date" => $line[2],
							"size" => $line[3],
							"name" => $line[4],
							"ext" => $line[5],
						);
						
						$path_parts = pathinfo(strtolower($entry['name']));
						$processpath = true;
						
						foreach($offdir as $dir)
						{
							if($dir == substr($entry["name"], 0, min(strlen($dir), strlen($entry["name"]))))
							{
								$processpath = false;
							}
						}
							
						$fpath = substr($entry['name'], 0, strlen($entry['name']) - strlen($path_parts['basename']));
						
						if($processpath && !in_array(strtolower($entry['ext']), $offext) &&  
							!in_array(strtolower($entry['name']), $offfile))
						{
							$olddata[$line[0]] = $entry;
 						}
					}
					
					fclose($datafile);
				}
			}
			
			if(count($olddata) > 0)
			{
				$oldsettings = array_shift($olddata);
			}
	
			if(!file_exists($scandir))
			{
				// Пишем информацию в лог файл .scan_dirs.log который лежит в папке /logs/
				// $this->slog("Directory $scandir does not exist.\n");
				exit(2);
			}
			
			$changed = array();
			$deleted = array();
			$added = array();
			$newdata = array();

			$it = new RecursiveDirectoryIterator($scandir);
			$iterator = new RecursiveIteratorIterator($it);
			$fff = iterator_to_array($iterator, true);

			foreach($fff as $filename)
			{
				$shortname = substr($filename, strlen($scandir));
				$justname = basename($filename);
				$fpath = substr($shortname, 0, strlen($shortname) - strlen($justname));
				$path_parts = pathinfo($filename);
				$extension = strtolower(@$path_parts['extension']);

				$processpath = true;
				
				foreach($offdir as $dir)
				{
					if($dir == substr($shortname, 0, min(strlen($dir), strlen($shortname))))
					{
						$processpath = false;
					}
 				}

				if(!in_array(strtolower($extension), $offext) && $processpath && !in_array(strtolower($shortname), $offfile))
				{
					switch($mode)
					{
						case 'attributes' : $fhash = md5(filesize($filename) . filemtime($filename));
							break;
						case 'content' : $fhash = md5_file($filename);
							break;
					}

					$filedata = array(
						"namehash" => md5($shortname),
						"checkhash" => $fhash,
						"date" => date("Y-m-d H:i:s", filemtime($filename)),
						"size" => filesize($filename),
						"name" => $shortname,
						"ext" => $extension,
					);

					$newdata[$filedata["namehash"]] = $filedata;

					if(isset($olddata[$filedata["namehash"]]))
					{
						if($olddata[$filedata["namehash"]]["checkhash"] != $filedata["checkhash"])
						{
							$changed[$filedata["namehash"]]["old"] = $olddata[$filedata["namehash"]];
							$changed[$filedata["namehash"]]["new"] = $filedata;
						}
						
						unset($olddata[$filedata["namehash"]]);
						
					}
					else
					{
						if(stripos($filedata["checkhash"], "excluded") === false)
						{
							$added[$filedata["namehash"]] = $filedata;
						}
					}
				}
			}

			If(count($olddata) > 0)
			{
				foreach($olddata as $index => $filedata)
				{
					if(stripos($filedata["checkhash"], "excluded") === false)
					{
						$deleted[$index] = $filedata;
					}
 				}
			}

			// Отправляем сообщите администратору в случае изменения файлов.
			$changes = "";
			
			if(count($changed) > 0)
			{
				$changes .= "Измененные файлы:\n";
				
				foreach($changed as $filedata)
				{
					$changes .= $filedata["old"]["name"] . " (" . 
						$filedata["old"]["date"]."), " . $filedata["old"]["size"] . 
							" -> " . $filedata["new"]["size"] . " bytes\n";
				}
				
				$changes .= "\n";
			
			}
			
			if(count($added) > 0)
			{
				$changes .= "Добавленные новые файлы:\n";
				
				foreach($added as $filedata)
				{
					$changes .= " " . $filedata["name"] . " (".$filedata["date"]."), " . 
						$filedata["size"] . " bytes\n";
				}
				
				$changes .= "\n";
				
			}
			if(count($deleted) > 0)
			{
				$changes .= "Удаленные файлы:\n";
				
				foreach($deleted as $filedata)
				{
					$changes .= " " . $filedata["name"] . " (" . $filedata["date"]."), " . 
						$filedata["size"] . " bytes\n";
				}
				
				$changes .= "\n";
				
			}
			
			//Закомментирована отладка
//			echo $changes;
			
			$summary = count($newdata) . " просканировано файлов из них, " . count($changed) . 
				" измененные, " . count($added) . " новые, " . count($deleted) . " удаленные\n";
				
			//Формируем письмо для отправки на указанную почту

			if(count($changed) + count($added) + count($deleted) > 0)
			{
				if($emailAddressToAlert != "")
				{
					$headers = "Return-path: $emailAddressToAlert\r\n";
					$headers .= "Reply-to: $emailAddressToAlert\r\n";
					$headers .= "X-Priority: 1\r\n";
					$headers .= "MIME-Version: 1.0\r\n";
					$headers .= "Content-type: text/plain; charset=UTF-8\r\n";
					$headers .= "Content-Transfer-Encoding: 7bit\r\n";
					$headers .= "From: $emailAddressToAlert\r\n";
					$headers .= "Organization: $servername\r\n";
					$headers .= "\n\n";
					
					//Отправляем новое значение хеш-на электронную почту.
					$emailBody = "Файлы в папке '$scandir' были изменены с момента " . $oldsettings["date"] . ".\n" . $summary . "\n".
					
					//Указываем время за которое время было выполнено сканирование.
					$changes. "\nВремя сканирования " . (microtime(true)-$time_start) . " seconds.\n";
					
					//Отправляем сообщение на email.
					mail($emailAddressToAlert, $emailSubject, $emailBody, $headers);
				}
			}

			// Пишем новые данные в файл.
			$datafile = fopen($scandir . $datafilename, "w");
			
			fwrite($datafile, "---\t---\t" . date("Y-m-d H:i:s") . "\t---\t" . $scandir . "\t" . $mode . "\n");
			
			foreach($newdata as $filedata)
			{
				fwrite($datafile, $filedata["namehash"] . "\t". $filedata["checkhash"] . "\t" . 
					$filedata["date"] . "\t" . $filedata["size"] . "\t" . $filedata["name"] . 
						"\t" . $filedata["ext"] . "\n");
			}
			
			fclose($datafile);
			
			//Закомментирована отладка
//			echo "\nDone in " . (microtime(true)-$time_start) . " seconds!</pre></html>"; 

			if(!file_exists($scandir))
			{
				// Пишем информацию в лог файл .scan_dirs.log который лежит в папке /logs/
				$this->slog("Directory $scandir does not exist.\n"); 
			}
			
			// Пишем информацию в лог файл .scan_dirs.log который лежит в папке /logs/
			$this->slog(date("Y-m-d H:i:s") . " Processing '$scandir'\n"); 
			
			// Пишем информацию в лог файл .scan_dirs.log который лежит в папке /logs/
			$this->slog($summary);
			
			// Пишем информацию в лог файл .scan_dirs.log который лежит в папке /logs/
			$this->slog(date("Y-m-d H:i:s") . " Done in " . (microtime(true) - $time_start) . " seconds!\n\n"); 

		}
}		
	/**
	* Пишем в лог файл .scan_dirs.log в папку /logs/ для разработки и тестирования.
	*/
	
	PRIVATE FUNCTION slog($string)
	{
		if ($this->params->get('scandir_logs', '1') == '1')
		{
			file_put_contents($_SERVER['DOCUMENT_ROOT'] . "/logs/.scan_dirs.log", $string, FILE_APPEND);
		}			 
	}

}
