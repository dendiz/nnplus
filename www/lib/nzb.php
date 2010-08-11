<?php
require_once("config.php");
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/site.php");
require_once(WWW_DIR."/lib/groups.php");
require_once(WWW_DIR."/lib/nntp.php");
require_once(WWW_DIR."/lib/category.php");

class NZB 
{
	function NZB() 
	{
		if(isset($_SERVER['HTTP_USER_AGENT']) && strlen($_SERVER['HTTP_USER_AGENT']) > 0)
			$this->n = "\n<BR>";
		else
			$this->n = "\n";
			
		$s = new Sites();
		$site = $s->get();
		$this->compressedHeaders = ($site->compressedheaders == "1" ? true : false);	
		$this->maxMssgs = 20000; //fetch this amount of messages at the time
		$this->NewGroupDaysToScan = 3;	//how many days back to scan for new groups
	}
	
	//
	// Writes out the nzb when processing releases. Moved out of smarty due to memory issues
	// of holding all parts in an array.
	//
	function writeNZBforReleaseId($relid, $relguid, $name, $catId, $path, $echooutput=false)
	{

		$db = new DB();
		$binaries = array();
		$cat = new Category();
		$catrow = $cat->getById($catId);

		$fp = gzopen($path, "w"); 
		if ($fp)
		{
			gzwrite($fp, "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n"); 
			gzwrite($fp, "<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n"); 
			gzwrite($fp, "<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n\n"); 
			gzwrite($fp, "<head>\n"); 
			if ($catrow)
				gzwrite($fp, " <meta type=\"category\">".htmlentities($catrow["title"], ENT_QUOTES)."</meta>\n"); 
			if ($name != "")
				gzwrite($fp, " <meta type=\"name\">".$name."</meta>\n"); 
			gzwrite($fp, "</head>\n\n"); 
	
			$result = $db->queryDirect(sprintf("SELECT binaries.*, UNIX_TIMESTAMP(date) AS unixdate, groups.name as groupname FROM binaries inner join groups on binaries.groupID = groups.ID WHERE binaries.releaseID = %d ORDER BY binaries.name", $relid));
			while ($binrow = mysql_fetch_array($result, MYSQL_BOTH)) 
			{				
				$groups = array();
				$groupsRaw = explode(' ', $binrow['xref']);
				foreach($groupsRaw as $grp) 
					if (preg_match('/^([a-z0-9\.\-_]+):(\d+)?$/i', $grp, $match) && strtolower($grp) !== 'xref') 
						$groups[] = $match[1];
				
				if (count($groups) == 0)
					$groups[] = $binrow["groupname"];

				gzwrite($fp, "<file poster=\"".htmlentities($binrow["fromname"], ENT_QUOTES)."\" date=\"".$binrow["unixdate"]."\" subject=\"".htmlentities($binrow["name"], ENT_QUOTES)." (1/".$binrow["totalParts"]."\">\n"); 
				gzwrite($fp, " <groups>\n"); 
				foreach ($groups as $group)
					gzwrite($fp, "  <group>".$group."</group>\n"); 
				gzwrite($fp, " </groups>\n"); 
				gzwrite($fp, " <segments>\n"); 

				$resparts = $db->queryDirect(sprintf("SELECT size, partnumber, messageID FROM parts WHERE binaryID = %d ORDER BY partnumber", $binrow["ID"]));
				while ($partsrow = mysql_fetch_array($resparts, MYSQL_BOTH)) 
				{				
					gzwrite($fp, "  <segment bytes=\"".$partsrow["size"]."\" number=\"".$partsrow["partnumber"]."\">".htmlentities($partsrow["messageID"], ENT_QUOTES)."</segment>\n"); 
				}
				gzwrite($fp, " </segments>\n</file>\n"); 
			}
			gzwrite($fp, "<!-- generated by newznab -->\n</nzb>"); 
			gzclose($fp); 
		}
	}
	
	//
	// builds a full path to the nzb file on disk. nzbs are stored in a subdir of their first char.
	//
	function getNZBPath($releaseGuid, $sitenzbpath = "", $createIfDoesntExist = false)
	{
		if ($sitenzbpath == "")
		{
			$s = new Sites;
			$site = $s->get();
			$sitenzbpath = $site->nzbpath;
		}

		$nzbpath = $sitenzbpath.substr($releaseGuid, 0, 1)."/";

		if ($createIfDoesntExist && !file_exists($nzbpath))
				mkdir($nzbpath);
		
		return $nzbpath.$releaseGuid.".nzb.gz";
	}

	//
	// Update all active groups categories and descriptions
	//
	function backfillAllGroups()
	{
			$n = $this->n;
			$groups = new Groups;
			$res = $groups->getActive();

			if ($res)
			{
				$nntp = new Nntp();
				$nntp->doConnect();

				foreach($res as $groupArr)
				{
					$this->message = array();
					$this->backfillGroup($nntp, $groupArr);
				}

				$nntp->doQuit();
			}
			else
			{
				echo "No groups specified. Ensure groups are added to newznab's database for updating.$n";
			}
	}

	function updateAllGroups() 
	{
		$n = $this->n;
		$groups = new Groups;
		$res = $groups->getActive();

		if ($res)
		{
			$nntp = new Nntp();
			$nntp->doConnect();

			foreach($res as $groupArr) 
			{
				$this->message = array();
				$this->updateGroup($nntp, $groupArr);
			}
			
			$nntp->doQuit();	
		}
		else
		{
			echo "No groups specified. Ensure site.groupfilter is populated and run group-update.$n";
		}		
	}	


	function postdate($nntp,$post,$debug=true) //returns single timestamp from a local article number
	{
		$msgs = $nntp->getOverview($post."-".$post,true,false);
		$date = $msgs[0]['Date'];
		if($debug) echo "DEBUG: postdate for post $post came back $date (or ";
		$date = strtotime($date);
		if($debug) echo "$date seconds unixtime)".$this->n;
	 return $date;
	}
	function daytopost($nntp,$group,$days,$debug=true)
	{
		$pddebug = 0; //DEBUG every postdate call?!?!
		if($debug) echo "INFO: daytopost finding post for $group $days days back.\n";
		$data = $nntp->selectGroup($group);
		$goaldate = date('U')-(86400*$days); //goaltimestamp
                $totalnumberofarticles = $data['last'] - $data['first'];
                $upperbound = $data['last'];
                $lowerbound = $data['first'];

                if($debug) echo("Total# =$totalnumberofarticles\nUpper  =$upperbound\nLower  =$lowerbound\nGoal   =$goaldate\n");
		if($data['last']==PHP_INT_MAX) { echo "ERROR: Group data is coming back as php's max value.  Did you patch your PEAR Net_NNTP Package?  Check install notes for more details.$n"; die(); }

		if($goaldate < $this->postdate($nntp,$data['first'],$pddebug) || $goaldate > $this->postdate($nntp,$data['last'],$pddebug))
		{
			echo "WARNING: daytopost: Goal date out of range. Returning start post.\n";
			if($debug) echo "Debug: goaldate=$goaldate\nFirstdate:".$this->postdate($nntp,$data['first'],$pddebug)."\nLastdate:".$this->postdate($nntp,$data['last'],$pddebug)."\n";
			return $data['first'];
		}
		$this->startdate = $this->postdate($nntp,$data['first'],$pddebug); $enddate = $this->postdate($nntp,$data['last'],$pddebug);
		if($debug) echo("Start  =".$data['first']."\nSrtdate=".$this->startdate."\nEnd    =".$data['last']."\nEndDate=$enddate\n");
		$interval = floor(($upperbound - $lowerbound) * 0.5);
		$dateofnextone = "";
		$templowered = "";
		while(!$dateofnextone)
		{  $dateofnextone = $this->postdate($nntp,($upperbound-1),$pddebug); }

		while($dateofnextone > $goaldate)  //while upperbound is not right above timestamp
		{
			while($this->postdate($nntp,($upperbound-$interval),$pddebug)>$goaldate)
			{
				$upperbound = $upperbound - $interval;
				if($debug) echo "Lowered upperbound $interval articles.\n";
			}
			if(!$templowered)
			{
				$interval = ceil(($interval /2));
				if($debug) echo "Set interval to $interval articles. DEBUG: $upperbound\n";
		 	}
		 	$dateofnextone = $this->postdate($nntp,($upperbound-1),$pddebug);
			while(!$dateofnextone)
			{  $dateofnextone = $this->postdate($nntp,($upperbound-1),$pddebug); }
	 	}
		echo "Determined to be article $upperbound\n";
		return $upperbound;
	}
    
	function nzbFileList($nzb) 
	{
	    $result = array();
	   
	    $nzb = str_replace("\x0F", "", $nzb);
	   	$num_pars = 0;
	    $xml = @simplexml_load_string($nzb);
	    if (!$xml || strtolower($xml->getName()) != 'nzb') 
	      return false;

	    $i=0;
	    foreach($xml->file as $file) 
	    {
			//subject
			$title = $file->attributes()->subject;
			if (preg_match('/\.par2/i', $title)) 
				$num_pars++;

			$result[$i]['title'] = "$title";

			//filesize
			$filesize = 0;
			foreach($file->segments->segment as $segment)
				$filesize += $segment->attributes()->bytes;

			$result[$i]['size'] = $filesize;

			$i++;
	    }
	   
	    return $result;
	}
	

function scan($nntp,$db,$groupArr,$first,$last)
{
	$n = $this->n;
	echo " getting $first to $last: $n";
	$this->startHeaders = microtime(true);
	if ($this->compressedHeaders)
		$msgs = $nntp->getXOverview($first."-".$last, true, false);
	else
		$msgs = $nntp->getOverview($first."-".$last, true, false);
	$timeHeaders = number_format(microtime(true) - $this->startHeaders, 2);

	if(PEAR::isError($msgs))
	{
		echo "Error {$msgs->code}: {$msgs->message}$n";
		echo "Skipping group$n";
		break;
	}

	$this->startUpdate = microtime(true);
	//check that we got the correct response
	if (is_array($msgs)) //to within 2 parts per batch missing from server
	{	       //loop headers, figure out parts
		foreach($msgs AS $msg)
		{
			$pattern = '/\((\d+)\/(\d+)\)$/i';
			if (!isset($msg['Subject']) || !preg_match($pattern, $msg['Subject'], $matches)) // not a binary post most likely.. continue
				continue;
				//Filter for only u4all posts in boneless, uncomment the next few lines to enable.
//			if (preg_match('/alt.binaries.boneless/i',$msg['Xref']) && !preg_match('/usenet-4all|u4all|usenet4all/i', $msg['Subject']))
//			{
//				continue;
//			}
			if(is_numeric($matches[1]) && is_numeric($matches[2]))
			{
				array_map('trim', $matches);
				$subject = trim(preg_replace($pattern, '', $msg['Subject']));
	
				if(!isset($this->message[$subject]))
				{
					$this->message[$subject] = $msg;
					$this->message[$subject]['MaxParts'] = (int)$matches[2];
					$this->message[$subject]['Date'] = strtotime($this->message[$subject]['Date']);
				}
				if((int)$matches[1] > 0)
				{
					$this->message[$subject]['Parts'][(int)$matches[1]] = array('Message-ID' => substr($msg['Message-ID'],1,-1), 'number' => $msg['Number'], 'part' => (int)$matches[1], 'size' => $msg['Bytes']);
				}
			}
		}
		$count = 0;
		$updatecount = 0;
		$partcount = 0;

		if(isset($this->message) && count($this->message))
		{
			//insert binaries and parts into database. when binary already exists; only insert new parts
			foreach($this->message AS $subject => $data)
			{
				if(isset($data['Parts']) && count($data['Parts']) > 0 && $subject != '')
				{
					$res = $db->queryOneRow(sprintf("SELECT ID FROM binaries WHERE name = %s AND fromname = %s AND groupID = %d", $db->escapeString($subject), $db->escapeString($data['From']), $groupArr['ID']));
					if(!$res)
					{
						$binaryID = $db->queryInsert(sprintf("INSERT INTO binaries (name, fromname, date, xref, totalparts, groupID, dateadded) VALUES (%s, %s, FROM_UNIXTIME(%s), %s, %s, %d, now())", $db->escapeString($subject), $db->escapeString($data['From']), $db->escapeString($data['Date']), $db->escapeString($data['Xref']), $db->escapeString($data['MaxParts']), $groupArr['ID']));
						$count++;
					}
					else
					{
						$binaryID = $res["ID"];
						$updatecount++;
					}

					foreach($data['Parts'] AS $partdata)
					{
						$partcount++;
						$db->queryInsert(sprintf("INSERT INTO parts (binaryID, messageID, number, partnumber, size, dateadded) VALUES (%d, %s, %s, %s, %s, now())", $binaryID, $db->escapeString($partdata['Message-ID']), $db->escapeString($partdata['number']), $db->escapeString(round($partdata['part'])), $db->escapeString($partdata['size'])));
					}
				}
			}
		}	
		// update the group with the last update record.
		//commented by pmow to make this bi-directional.  see the following line in updateGroup
		//$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($last), $groupArr['ID']));
		$timeUpdate = number_format(microtime(true) - $this->startUpdate, 2);
		$timeLoop = number_format(microtime(true)-$this->startLoop, 2);

		echo "Received $count new binaries$n";
		echo "Updated $updatecount binaries$n";
		echo "Info: ".(str_replace('alt.binaries', 'a.b', $groupArr['name']))." Headers $timeHeaders, Update/Insert $timeUpdate, Range $timeLoop seconds$n";
	
		unset($this->message);
		unset($msgs);
		unset($msg);
		unset($data);	
	}
	else
	{
		// TODO: fix some max attemps variable.. somewhere
		echo "Error: Can't get parts from server (msgs not array)\n";
		echo "Skipping group$n";
		break;
	}


}

	function updateGroup($nntp, $groupArr)
	{
		$db = new DB();
		$n = $this->n;
		$attempts = 0;
		$this->startGroup = microtime(true);

		$data = $nntp->selectGroup($groupArr['name']);
		if(PEAR::isError($data))
		{
			echo "Could not select group (bad name?): {$groupArr['name']}$n";
			return;
		}

		//get first and last part numbers from newsgroup
		$last = $orglast = $data['last'];
		if($groupArr['last_record'] == 0)
		{
			//
			// for new newsgroups - determine here how far you want to go back.
			//
			$first = $this->daytopost($nntp,$groupArr['name'],$this->NewGroupDaysToScan,TRUE);
			$db->query(sprintf("UPDATE groups SET first_record = %s WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
		}
		else
		{
			$first = $groupArr['last_record'] + 1;
		}

		//calculate total number of parts
		$total = $last - $first;

		//if total is bigger than 0 it means we have new parts in the newsgroup
		if(($data['last']-$data['first'])<=5) //deactivate empty groups
			$db->query(sprintf("UPDATE groups SET active = %s, last_updated = now() WHERE ID = %d", $db->escapeString('0'), $groupArr['ID']));
		if($total > 0)
		{

			echo "Group ".$data["group"]." has ".$data['first']." - ".$data['last'].", or ~";
			echo((int) (($this->postdate($nntp,$data['last'],FALSE) - $this->postdate($nntp,$data['first'],FALSE))/86400));
			echo " days - Local last = ".$groupArr['last_record'];
			if($groupArr['last_record']==0)
				echo(", we are getting ".$this->NewGroupDaysToScan." days worth.");
			echo $n.'Using compression: '.(($this->compressedHeaders)?'Yes':'No').$n;
			$done = false;
			$last = $first + $this->maxMssgs - 1;
			if($last > $orglast)
				$last = $orglast;

			//get all the parts (in portions of $this->maxMssgs to not use too much memory)
			while($done === false)
			{
				$this->startLoop = microtime(true);

				echo "Getting ".($last-$first+1)." parts (".($orglast - $last)." in queue)";
				flush();

				//get headers from newsgroup
				$this->scan($nntp,$db,$groupArr,$first,$last);
				$db->query(sprintf("UPDATE groups SET last_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($last), $groupArr['ID']));
				if($last==$orglast)
					$done = true;
				else
				{
					$first = $last + 1;
					$last = $first + $this->maxMssgs - 1;
					if($last > $orglast)
						$last = $orglast;
				}
			}
			$timeGroup = number_format(microtime(true) - $this->startGroup, 2);
			echo "Group processed in $timeGroup seconds $n";
		}
		else
		{
			echo "No new records for ".$data["group"]." (first $first last $last total $total) grouplast ".$groupArr['last_record']."$n";

		}
	}
	function backfillGroup($nntp, $groupArr)
	{
		$db = new DB();
		$n = $this->n;
		$attempts = 0;
		$this->startGroup = microtime(true);

		$data = $nntp->selectGroup($groupArr['name']);
		if(PEAR::isError($data))
		{
			echo "Could not select group (bad name?): {$groupArr['name']}$n";
			return;
		}
		$targetpost = $this->daytopost($nntp,$groupArr['name'],$groupArr['backfill_target'],TRUE); //get targetpost based on days target
	       if($groupArr['first_record'] == 0 || $groupArr['backfill_target'] == 0)
		{
			echo "Group ".$groupArr['name']." has invalid numbers.  Have you run update on it?  Have you set the backfill days amount?$n";
			return;
		}
		
		echo "Group ".$data["group"].": server has ".$data['first']." - ".$data['last'].", or ~";
		echo((int) (($this->postdate($nntp,$data['last'],FALSE) - $this->postdate($nntp,$data['first'],FALSE))/86400));
		echo " days.".$n."Local first = ".$groupArr['first_record']." (";
		echo((int) ((date('U') - $this->postdate($nntp,$groupArr['first_record'],FALSE))/86400));
		echo " days).  Backfill target of ".$GroupArr['backfill_target']."days is post $targetpost.$n";

		if($targetpost >= $groupArr['first_record'])	//if our estimate comes back with stuff we already have, finish
		{
			echo "Nothing to do, we already have the target post.$n";
		}
		//get first and last part numbers from newsgroup
		if($targetpost < $data['first'])
		{
			echo "WARNING: Backfill came back as before server's first.  Setting targetpost to server first.$n";
			$targetpost = $data['first'];
		}
		//calculate total number of parts
		$total = $groupArr['first_record'] - $targetpost;
		$done = false;
		//set first and last, moving the window by maxxMssgs
		$last = $groupArr['first_record'] - 1;
		$first = $last - $this->maxMssgs + 1; //set initial "chunk"
		if($targetpost > $first)	//just in case this is the last chunk we needed
			$first = $targetpost;
		while($done === false)
		{
			$this->startLoop = microtime(true);
			echo "Getting ".($last-$first+1)." parts (".($first-$targetpost)." in queue)";
			flush();
			$this->scan($nntp,$db,$groupArr,$first,$last);
			$db->query(sprintf("UPDATE groups SET first_record = %s, last_updated = now() WHERE ID = %d", $db->escapeString($first), $groupArr['ID']));
			if($first==$targetpost)
				$done = true;
			else
			{	//Keep going: set new last, new first, check for last chunk.
				$last = $first - 1;
				$first = $last - $this->maxMssgs + 1;
				if($targetpost > $first)
					$first = $targetpost;
			}
		}
		$timeGroup = number_format(microtime(true) - $this->startGroup, 2);
		echo "Group processed in $timeGroup seconds $n";
	}


}
?>
