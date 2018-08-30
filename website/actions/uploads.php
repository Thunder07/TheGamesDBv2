<?php
require_once __DIR__ . "/../../include/TGDB.API.php";
require_once __DIR__ . "/../include/UploadHandler.fineupload.class.php";
require_once __DIR__ . "/../include/ErrorPage.class.php";
require_once __DIR__ . "/../include/login.phpbb.class.php";
require_once __DIR__ . "/../include/WebUtils.class.php";

function save_image($original_image, $dest_image, $type)
{
	if(file_exists($original_image))
	{
		include __DIR__ . "/../../vendor/autoload.php";
		try
		{
			if(!file_exists(dirname($dest_image)))
			{
				mkdir(dirname($dest_image), 0755, true);
			}
			$image = new \claviska\SimpleImage();
			$image = $image->fromFile($original_image);
			$type = ($type == 'jpg') ? 'jpeg' : $type;
			$image->toFile($dest_image, "image/$type", 100);
			return true;
		}
		catch(Exception $err)
		{
			error_log($err);
			return false;
		}
	}
}

function returnJSONAndDie($msg)
{
	global $tmp_image_out_path, $image_out_path;
	if(isset($tmp_image_out_path) && file_exists($tmp_image_out_path))
	{
		unlink($tmp_image_out_path);
	}
	if(isset($image_out_path) && file_exists($image_out_path))
	{
		unlink($image_out_path);
	}
	echo json_encode(array("error" => $msg));
	die();
}

$_user = phpBBUser::getInstance();
if(!$_user->isLoggedIn())
{
	returnJSONAndDie(ErrorPage::$MSG_NOT_LOGGED_IN_EDIT_ERROR);
}
else
{
	if(!$_user->hasPermission('u_edit_games'))
	{
		returnJSONAndDie(ErrorPage::$MSG_NO_PERMISSION_TO_EDIT_ERROR);
	}
}
$Fields = ['game_id', 'type', 'subtype'];
{
	foreach($Fields as $field)
	{
		if(!isset($_REQUEST[$field]))
		{
			returnJSONAndDie(ErrorPage::$MSG_MISSING_PARAM_ERROR . ": ($field).");
		}
	}
	// TODO: move these hardcoded values to a table, but this will do for now
	switch($_REQUEST['type'])
	{
		case 'boxart':
			if($_REQUEST['subtype'] == 'front' || $_REQUEST['subtype'] == 'back')
			{
				break;
			}
			returnJSONAndDie("Invalid subtype selection: " . $_REQUEST['subtype']);
		case 'fanart':
		case 'banner':
		case 'screenshot':
		case 'clearlogo':
			if(empty($_REQUEST['subtype']))
			{
				break;
			}
			returnJSONAndDie("Invalid subtype selection: " . $_REQUEST['subtype']);
		default:
			returnJSONAndDie("Invalid type selection: " . $_REQUEST['type']);
	}
}


$uploader = new UploadHandler();

$uploader->allowedExtensions = array('jpe', 'jpg', 'jpeg', 'gif', 'png', 'bmp');
$uploader->sizeLimit = 5 * 1024 *1024;

$uploader->inputName = "qqfile";

function get_request_method()
{
	global $HTTP_RAW_POST_DATA;

	if(isset($HTTP_RAW_POST_DATA))
	{
		parse_str($HTTP_RAW_POST_DATA, $_POST);
	}

	if (isset($_POST["_method"]) && $_POST["_method"] != null)
	{
		return $_POST["_method"];
	}

	return $_SERVER["REQUEST_METHOD"];
}

if (get_request_method() == "POST")
{
	header("Content-Type: text/plain");

	$tmp_path = __DIR__ . "/../../cdn/images/tmp/original/" . $_REQUEST['type'];
	$path = __DIR__ . "/../../cdn/images/original/" . $_REQUEST['type'];
	if(!empty($_REQUEST['subtype']))
	{
		$tmp_path .= "/" .  $_REQUEST['subtype'];
		$path .= "/" .  $_REQUEST['subtype'];
	}

	$API = TGDB::getInstance();
	$covers = $API->GetGameBoxartByID($_REQUEST['game_id'], 0, 30, $_REQUEST['type']);
	if(!empty($covers) && ($covers = $covers[$_REQUEST['game_id']]) && count($covers) > 5)
	{
		returnJSONAndDie("Max (5) allowed uploaded images has been reached.");
	}

	if($_REQUEST['type'] == 'clearlogo')
	{
		$type = "png";
	}
	else
	{
		$type = "jpg";
		if($_REQUEST['type'] == 'boxart')
		{
			// by forcing the name to "-1.$type", we'll always replace the cover with new upload
			$image_name = $_REQUEST['game_id'] . "-1.$type";
		}
	}
	if(!isset($image_name))
	{
		for($i = 1; $i < 6; ++$i)
		{
			$tmp_name = $_REQUEST['game_id'] . "-$i.$type";
			if(!file_exists($path . "/" . $tmp_name))
			{
				$image_name = $tmp_name;
				break;
			}
		}
		if(!isset($image_name))
		{
			die("Failed to find an image_name");
		}
	}

	if(!file_exists($tmp_path))
	{
		mkdir($tmp_path, 0755, true);
	}
	$result = $uploader->handleUpload($tmp_path, $image_name);
	$result["uploadName"] = $uploader->getUploadName();

	if(isset($result['success']))
	{
		$tmp_image_out_path = $tmp_path . "/" . $image_name;
		$image_out_path = $path . "/" . $image_name;
		$result['final_out'] = $image_out_path;
		if(save_image($tmp_image_out_path, $image_out_path, $type))
		{
			if($_REQUEST['type'] == 'boxart')
			{
				if(!empty($covers))
				{
					foreach($covers as $cover)
					{
						if($_REQUEST['subtype'] == $cover->side)
						{
							$sql_image_path = $cover->filename;
							break;
						}
					}
					if(isset($sql_image_path))
					{
						$sizes = ["small", "thumb", "cropped_center_thumb", "cropped_center_thumb_square", "medium", "large"];
						WebUtils::purgeCDNCache($sql_image_path);
						if(basename($sql_image_path) != $image_name)
						{
							array_push($sizes, 'original');
						}
						foreach($sizes as $size)
						{
							$image_to_delete = __DIR__ . "/../../cdn/images/$size/" . $sql_image_path;
							if(file_exists($image_to_delete))
							{
								unlink($image_to_delete);
							}
						}
						if($_REQUEST['subtype'] == $cover->side)
						{
							$res = $API->DeleteAndInsertGameImages($_user->GetUserID(), $cover->id, $_REQUEST['game_id'], $_REQUEST['type'],
								$_REQUEST['type'] . "/" . $_REQUEST['subtype'] . "/" . $image_name, $_REQUEST['subtype']);
								echo json_encode($result); return;
						}
					}
				}
				$res = $API->InsertGameImages($_user->GetUserID(), $_REQUEST['game_id'], $_REQUEST['type'],
					$_REQUEST['type'] . "/" . $_REQUEST['subtype'] . "/" . $image_name, $_REQUEST['subtype']);
			}
			else
			{
				$res = $API->InsertGameImages($_user->GetUserID(), $_REQUEST['game_id'], $_REQUEST['type'],
					$_REQUEST['type'] . "/" . $image_name);
			}
			
			if(!isset($res) || !$res)
			{
				returnJSONAndDie("Failed to update database.");
			}
		}
		else
		{
			returnJSONAndDie("Failed save image." . $image_out_path);
		}
	}

	echo json_encode($result);
}
else
{
	header("HTTP/1.0 405 Method Not Allowed");
}