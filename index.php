<?php
	
require_once('shep_config.php');


//ini_set('display_errors', 'On');
//error_reporting(E_ALL);

class database {

    //init -- set up the connection
    function database($host, $user, $pwd, $database) {
    
        if(!mysql_connect($host, $user, $pwd)) return false;
        if(mysql_select_db($database)) return true;
    
    }
    
    function db_insert($table, $field_arr, $data_arr) {
        
        $i = 1;
        foreach($field_arr as $field) {
            $field_str .= $field . ",";
            if($i == sizeof($field_arr)) $field_str = rtrim($field_str, ",");
            $i++;
        }
        
        $i = 1;
        foreach($data_arr as $data) {
            $data_str .= "'" . addslashes($data) . "',";
            if($i == sizeof($data_arr)) $data_str = rtrim($data_str, ",");
            $i++;
        }
        
        $query = "INSERT INTO ". $table . " (".$field_str.") VALUES (".$data_str.")";
        if($result = mysql_query($query)) return true;
        return false;
    }
    
    function db_select_one($table, $fields, $where) {
        
        $query = "SELECT ". $fields . " FROM " . $table . " " . $where;
        $result = mysql_query($query) or die(mysql_error() . "<br>" . $query);
        if($row = mysql_fetch_array($result)) return $row;
        
        return false;
    }

}


class craigslist {

    var $limit = 10; //used to limit number of search results per city

    function get_craigslist_search($location, $searchString, $telecommute = true) {
    
        if($telecommute == true) $telecommute = 'addOne=telecommuting';
        $url = "http://".$location.".craigslist.org/search/jjj?query=".$searchString."&catAbbreviation=jjj&".$telecommute;
        $content = $this->get_curl_contents($url);
        if(strstr($content, "Nothing found for that search")) return false;
        ////process content to get the search results only
        $parts = explode("-->", $content);
        $content = $parts[2];
        $parts = explode("<br><div>Sort by:", $content);
        $content = $parts[0];
        $content = "<b>".strtoupper($location)."</b><br>" . $content;
        $content = str_replace("href=\"/", "href=\"http://".$location.".craigslist.org/", $content);
        $temp_anchors = explode("<a href=\"", $content);
        
        $db = new database(DB_HOST, DB_USER, DB_PWD, DB_DATABASE);
        
        $i=0;
        foreach($temp_anchors as $anchor) {
            if(strstr($anchor, ".html")) {
                if($i >= $this->limit) break; ///limit results per city
                $bits = explode(">", $anchor);
                $bits[0] = str_replace("\"", "", $bits[0]);
                
                $id_str = strrchr($bits[0], "/");
                $id_str = str_replace("/", "", $id_str);
                $id = str_replace(".html", "", $id_str);
                ///check to see if this ad is in the database
                $rs = $db->db_select_one('craigslist_ads', 'clid', " WHERE clid = '" . $id . "'" );
                if($rs['clid'] != $id) {
                    $this->anchors[] = strip_tags($bits[0]);
                    $this->ids[] = $id;
                    $i++;
                }
            }
        }
        return $content;
    }

    function get_emails($link) {
        
        //grab email addresses and subject lines from ads and add them to an array
        $content = $this->get_curl_contents($link);
        $tmp = explode("mailto:", $content);
        $tmp2 = explode("?", $tmp[1]);
        $tmp2[0] = str_replace("\"", "", $tmp2[0]);
        $tmp3 = explode("body=", $tmp2[1]);
        $tmp4 = explode("subject=", $tmp3[0]);
        $subject = str_replace("subject=", "", $tmp3[0]);
        $subject = urldecode($subject);
        $subject = str_replace("&amp", "", $subject);
        $subject = str_replace(";", "", $subject);
        if(!stristr($content, 'equity')) {
            $this->subjects[] = $subject;
            $this->emails[] = $tmp2[0];
        } else {
            $this->subjects[] = "";
           $this->emails[] = "";
       }

    }

    private function get_curl_contents($url) {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        ob_start();
        curl_exec($ch);
        $content = ob_get_contents();
        ob_end_clean();
        curl_close($ch);
        return $content;
    }
    
    function send_email($email, $subject, $clid) {
        
        //$email      = 'dlsheppard2003@yahoo.com';
        $filename = "craigslist_email.html";
        $handle = fopen($filename, "r");
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=iso-8859-1" . "\r\n";        
        $headers .= 'From: '. NAME .' <'. EMAIL .'>' . "\r\n" .
                'Reply-To: ' . EMAIL . " \r\n" .
                'X-Mailer: PHP/' . phpversion() . "\r\n";

        if(mail($email, $subject, $contents, $headers)) $success = true;
        
        if($success == true) {
            $this->archive($clid, true, $email);
            return true;
        }
        return false;
    }
    
    function archive($clid, $applied, $email) {
        //archived ads will no longer show up in a search
        $db =  new database(DB_HOST, DB_USER, DB_PWD, DB_DATABASE);
        $table = 'craigslist_ads';
        $fields = array('email', 'clid', 'applied');
        $data = array($email, $clid, $applied);
        if($db->db_insert($table, $fields, $data)) return true;
        return false;
    }

    function render_file($filename) { 
    // function not written by me originally but mofified to suit my needs
    // this is the function that returns the formatted php code from this page
        if(file_exists($filename) && is_file($filename)) {
        ob_start();
            $code = highlight_file($filename, true);
            $counter = 1;
            $arr = explode('<br />', $code);
            echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family: monospace;">' . "\r\n";
            foreach($arr as $line) {
                echo '<tr>' . "\r\n";
                    echo '<td width="65px" nowrap style="color: #666;">' . $counter . ':</td>' . "\r\n";

                    // fix multi-line comment bug
                    if((strstr($line, '<span style="color: #FF8000">/*') !== false) && (strstr($line, '*/') !== false)) { // single line comment using /* */
                        $comments = false;
                        $startcolor = "orange";
                    }  
                    elseif(strstr($line, '<span style="color: #FF8000">/*') !== false) { // multi line comment using /* */
                        $startcolor = "orange";
                        $comments = true;
                    }  
                    else { // no comment marks found
                        $startcolor = "green";
                        if($comments) { // continuation of multi line comment
                            if(strstr($line, '*/') !== false) {
                                $comments = false;
                                $startcolor = "orange";
                            }  
                            else {
                                $comments = true;
                            }  
                        }  
                        else { // normal line  
                            $comments = false;
                            $startcolor = "green";
                        }  
                    }  
                    // end fix multi-line comment bug

                    if($comments)
                        echo '<td width="100%" nowrap style="color: orange;">' . $line . '</td>' . "\r\n";
                    else
                        echo '<td width="100%" nowrap style="color: ' . $startcolor . ';">' . $line . '</td>' . "\r\n";
	
                    echo '</tr>' . "\r\n";
                    $counter++;
            }  
            echo '</table>' . "\r\n";
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
        }  
        else {
            echo "<p>The file <i>$filename</i> could not be opened.</p>\r\n";
            return;
        }  
    } 

}

//procedural code follows ///////////////////////

$craig = new craigslist;

///$locations is the array of places to search such as $location.craigslist.org
$locations = array('atlanta',
                    'austin',
                    'boston',
                    'chicago',
                    'cleveland',
                    'dallas',
                    'denver',
                    'detroit',
                    'honolulu',
                    'houston',
                    //'inlandempire',
                    'kansascity',
                    'lasvegas',
                    'losangeles',
                    'miami',
                    'minneapolis',
                    'nashville',
                    'newyork',
                    'orangecounty',
                    'philadelphia',
                    'phoenix',
                    'portland',
                    'raleigh',
                    'sacramento',
                    'sandiego',
                    'seattle',
                    'sfbay',
                    'stlouis',
                    'tampa',
                    'washingtondc'
                    );

if(is_array($_REQUEST['job_action']) && sizeof($_REQUEST['job_action']) > 0) { //send emails, archive, or "save" (do nothing)

    for($i=0; $i < intval($_REQUEST['num_results']); $i++){
        
       if($_REQUEST['job_action'][$i] == 'apply') $success = $craig->send_email($_REQUEST['email'][$i], $_REQUEST['subject'][$i], $_REQUEST['clid'][$i]);
        
        if($_REQUEST['job_action'][$i] == 'archive') $craig->archive($_REQUEST['clid'][$i], false, $_REQUEST['email'][$i]);
    }
    
    if($success == true) echo "Messages Sent<br>";
}

if($_REQUEST['searchterms'] != '') { //perform the search of craigslist

    $searchterms = urlencode($_REQUEST['searchterms']);
    $content = '';

    foreach($locations as $location) {
        $craig->get_craigslist_search($location, $searchterms, $_REQUEST['telecommute']);
    }
    
    foreach($craig->anchors as $link) {
        $craig->get_emails($link);
    }
    
    for($i=0; $i < sizeof($craig->emails); $i++) {
        if($craig->emails[$i] != '' && $craig->subjects[$i] != '') {
            $form_items .= "Save <input type=\"radio\" name=\"job_action[".$i."]\" value=\"ignore\" checked=\"true\"> || ";
            $form_items .= "Archive <input type=\"radio\" name=\"job_action[".$i."]\" value=\"archive\" > || ";
            $form_items .= "Apply <input type=\"radio\" name=\"job_action[".$i."]\" value=\"apply\"> <a href=\"".$craig->anchors[$i]."\" target=\"_blank\" >" . $craig->subjects[$i] . "</a><br>";
            $form_items .= "<input type=\"hidden\" name=\"subject[".$i."]\" value=\"".$craig->subjects[$i]."\">";
            $form_items .= "<input type=\"hidden\" name=\"email[".$i."]\" value=\"".$craig->emails[$i]."\">";
            $form_items .= "<input type=\"hidden\" name=\"clid[".$i."]\" value=\"".$craig->ids[$i]."\">";
        }
    }
    $form_items .= "<input type=\"hidden\" name=\"num_results\" value=\"".sizeof($craig->subjects)."\">";
    $form_items .= "<input type=\"submit\" name=\"apply\" value=\"Apply and Archive\">";
    
} 



?>
<html>
<form action="index.php" method="post">
Search for: <input type="text" name="searchterms" value="<?php echo urldecode($_REQUEST['searchterms']); ?>"> <input type="checkbox" name="telecommute" <?php if($_REQUEST['telecommute'] == true) echo "checked"; ?>> Telecommute  <input type="submit" value="Search">
<br>
<? echo $form_items; ?>
</form>
</html> 