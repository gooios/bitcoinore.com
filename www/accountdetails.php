<?php

include ("includes/templates/header.php");

if(!$cookieValid) {
	header('Location: /');
	exit;
}
//Execute the following based on what $_POST["act"] is set to
$returnError = "";
$goodMessage = "";
if (isset($_POST["act"])) {
	$act = $_POST["act"];
	$inputAuthPin = mysql_real_escape_string(hash("sha256", $_POST["authPin"].$salt));


	//Check if authorization pin has been inputted correctly
	if($inputAuthPin == $authPin && $act){
		if($act == "cashOut"){

			//Get user's balance and send it to set address;
			//Does user have any money in their balance
			if($currentBalance > 0.01){
				$bitcoinController = new BitcoinClient($rpcType, $rpcUsername, $rpcPassword, $rpcHost);

				//Send $currentBalance to $paymentAddress
				//Validate that a $paymentAddress has been set & is valid before sending
				if (!empty($paymentAddress)) {
					$isValidAddress = $bitcoinController->validateaddress($paymentAddress);
				}

				if($isValidAddress){
					//Subtract TX feee
					$currentBalance = $currentBalance - 0.005;
					//Send money//
					if($bitcoinController->sendtoaddress($paymentAddress, $currentBalance)) {
						$paid = 0;
						$result = mysql_query("SELECT IFNULL(paid,'0') as paid FROM accountBalance WHERE userId=".$userId);
						if ($resultrow = mysql_fetch_object($result)) $paid = $resultrow->paid + $currentBalance;

						//Reduce balance amount to zero & make a ledger entry
						mysql_query("UPDATE `accountBalance` SET balance = '0', paid = '".$paid."' WHERE `userId` = '".$userId."'");

                                		mysql_query("INSERT INTO ledger (userId, transType, amount, sendAddress) ".
                                        		    " VALUES ".
                                        		    "('$userId', 'Debit_MP', '$currentBalance', '$paymentAddress')");

						$goodMessage = "You have successfully sent ".$currentBalance." to the following address:".$paymentAddress;
						//Set new variables so it appears on the page flawlessly
						$currentBalance = 0;
					}else{
						$returnError = "Commodity failed to send. Contact site support immediately.";
					}
				}else{
					$returnError = "Invalid or missing Bitcoin payment address.";
				}
			}else{
				$returnError = "You have no money in your account.";
			}
		}


		if($act == "updateDetails"){
			//Update user's details
			$newSendAddress = mysql_real_escape_string($_POST["paymentAddress"]);
			$newDonatePercent = mysql_real_escape_string($_POST["donatePercent"]);
			$newPayoutThreshold = mysql_real_escape_string($_POST["payoutThreshold"]);

			// check and force thresholds on donate percent and payout triggers
			$newPayoutThreshold = min(25, max(0, floatval($newPayoutThreshold)));
				if ($newPayoutThreshold < 0.10) { $newPayoutThreshold = 0; }
			$newDonatePercent = min(100, max(0, floatval($newDonatePercent)));

			$updateSuccess1 = mysql_query("UPDATE accountBalance SET sendAddress = '".$newSendAddress."', threshold = '".$newPayoutThreshold."' WHERE userId = ".$userId);

			if (!is_nan($newDonatePercent))
				$updateSuccess2 = mysql_query("UPDATE webUsers SET donate_percent='".$newDonatePercent."' WHERE id = ".$userId);
			else
				$returnError = "Donation % must be numeric.";

			if($updateSuccess1 && $updateSuccess2){
				$goodMessage = "Account details are now updated.";
				$paymentAddress = $newSendAddress;
				$donatePercent = $newDonatePercent;
				$payoutThreshold = $newPayoutThreshold;
			}
		}
                if($act == "Create Team"){
                        $teamName = mysql_real_escape_string($_POST["teamName"]);
			$teamPin = mysql_real_escape_string(hash("sha256", $_POST["teamPin"].$salt));
                        $testTeamQ = mysql_query("SELECT id FROM teams WHERE name = '".$teamName."' LIMIT 1");
                        //If not, create new team
                        if (($testTeamQ == false) || (mysql_num_rows($testTeamQ) == 0)) {
                                                mysql_query("INSERT INTO teams (admin_id, name, pin) ".
                                                            " VALUES ".
                                                            "('$userId', '$teamName', '$teamPin')");
                                                $goodMessage = "You have successfully created ".$teamName." Team!";
			
                        } else {
                                $returnError = "Team name already exists. Please choose a different name.";
                        }
		}
                if($act == "Leave Team"){
				 mysql_query("UPDATE webUsers set teamId=0 WHERE id = ".$userId);
                                 $goodMessage = "You have successfully quit from ".$teamName." Team!";
                }
                if($act == "Join Team"){
                        $teamId = mysql_real_escape_string($_POST["teamId"]);
			$teamAuthPinQ = mysql_query("SELECT pin FROM teams where id = '".$teamId."'");
			$result = mysql_fetch_object($teamAuthPinQ);
			$teamAuthPin = $result->pin;
                        $teamPin = mysql_real_escape_string(hash("sha256", $_POST["teamauthPin"].$salt));
			if ($teamAuthPin == $teamPin){

                                                mysql_query("UPDATE webUsers set teamId = ".$teamId." WHERE id = ".$userId);

                                                $goodMessage = "You have successfully joined the Team!";
			}else{
			$returnError = "Please enter the correct Team Pin";
			}
                }

		if($act == "updatePassword"){
			//Update password
			$oldPass = hash("sha256", mysql_real_escape_string($_POST["currentPassword"]).$salt);
			$newPass = mysql_real_escape_string($_POST["newPassword"]);
			$newPassConfirm = mysql_real_escape_string($_POST["newPassword2"]);

			//If hash $oldPass is the same as the DB already hashed password continue you with the password change
			if($oldPass == $hashedPass){
				//Check if new password is valid
				if($newPass != "" && strlen($newPass) > 6){
					//Change the password only if $newPass == $newPassConfirm
					if($newPass == $newPassConfirm){
						//Update hashed password
						$newHashedPass = hash("sha256", $newPass.$salt);
						$passchangeSuccess = mysql_query("UPDATE `webUsers` SET `pass` = '".$newHashedPass."' WHERE `id` = '".$userId."'");
						if($passchangeSuccess){
							$goodMessage = "Password successfully changed.";
						}else{
							$returnError = "Database Failure - Unable to change password";
						}
					}else if($newPass != $newPassConfirm){
						$returnError = "The \"New Password\" and \"New Password Repeat\" fields must match";
					}
				}else{
					$returnError = "Your new password is not valid, Must be longer then 6 characters";
				}

			}else if($oldPass != $hashedPass){
				//Typed in password dosent match DB password
				$returnError = "You must type in the correct current password before you can set a new password.";
			}
		}


	}else if($inputAuthPin != $authPin && $act){
		$returnError = "Authorization Pin is Invalid!";
	}

}

?>

<div class="block withsidebar">

        <div class="block_head">
                <div class="bheadl"></div>
                <div class="bheadr"></div>

                <h2>Welcome,
                <?php
                if($cookieValid) {

                        echo $userInfo->username . " ";

                        $account_type = 0;
                        $account_type = account_type($userInfo->id);

                        if ($account_type == 9) {
                                $account_type = "<b>Early-Adopter</b>: <b>0%</b> Pool Fee";
                        } else {
                                $account_type = "<b>Active Account</b>: <b>" .$settings->getsetting("sitepercent"). "%</b> Pool Fee";
                        }

                        echo "<font size='1px'>" .$account_type."</font> ";
                        echo "<font size='1px'><i>(You are <a href='/osList'>donating</a> <b></i>" .antiXss($donatePercent)."%</b> <i>of your earnings)</i></font>";
                } else {
                        echo "Guest";
                }
                ?>
                </h2>
        </div>          <!-- .block_head ends -->




        <div class="block_content">

                <div class="sidebar">
                        <?php include ("includes/leftsidebar.php"); ?>
                </div>          <!-- .sidebar ends -->


                <div class="sidebar_content">

<?php
//Display Error and Good Messages(If Any)
if ($goodMessage) { echo "<div class=\"message success\"><p>".antiXss($goodMessage)."</p></div>"; }
if ($returnError) { echo "<div class=\"message errormsg\"><p>".antiXss($returnError)."</p></div>"; }
?>

	<!-- Account details -->
                <div class="block" style="clear:none;">
                 <div class="block_head">
                  <div class="bheadl"></div>
                  <div class="bheadr"></div>
                  <h2>Account Details</h2>
                 </div>
                 <div class="block_content" style="padding:10px;">
		<form action="/accountdetails" method="post"><input type="hidden" name="act" value="updateDetails">
		<table>
			<tr><td>Username: </td><td><?php echo antiXss($userInfo->username);?></td></tr>
			<tr><td>User Id: </td><td><?php echo antiXss($userId); ?></td></tr>
			<tr><td><a href="api?api_key=<?php echo antiXss($userApiKey) ?>" style="color: blue" target="_blank">API</a> Key: </td><td><h6><font size="1"><?php echo antiXss($userApiKey); ?></font></h6></td></tr>
			<tr><td>Payment Address: </td><td><input type="text" name="paymentAddress" value="<?php echo antiXss($paymentAddress)?>" size="40"></td></tr>
			<tr><td>Donation %: </td><td><input type="text" name="donatePercent" value="<?php echo antiXss($donatePercent);?>" size="4"><font size="1"> [donation amount in percent (example: 0.5)]</font></td></tr>
			<tr><td>Automatic Payout Threshold: </td><td valign="top"><input type="text" name="payoutThreshold" value="<?php echo antiXss($payoutThreshold);?>" size="5" maxlength="5"> <font size="1">[0.10-25 BTC. Set to '0' for no auto payout]</font></td></tr>
			<tr><td>4 digit PIN: </td><td><input type="password" name="authPin" size="4" maxlength="4"><font size="1"> [The 4 digit PIN you chose when registering]</font></td></tr>
		</table>
		<input type="submit" class="submit long" value="Update Settings"></form>
                </div>          <!-- nested block ends -->
                <div class="bendl"></div>
                <div class="bendr"></div>
                </div>

        <!-- Team details -->

<?php 
/*
  $result = mysql_query("SELECT teamId FROM webUsers where id =".$userId);
$resultrow = mysql_fetch_object($result);
 $teamId = $resultrow->teamId;
?>
                <div class="block" style="clear:none;">
                 <div class="block_head">
                  <div class="bheadl"></div>
                  <div class="bheadr"></div>
                  <h2>Team Details</h2>
                 </div>
                <table>
                 <div class="block_content" style="padding:10px;">
<?
if ($teamId == "0"){
?>
                <form action="/accountdetails" method="post"><input type="hidden" name="act" value="teamDetails">
			<tr><td>Team Name: </td><td><input type="text" name="teamName" onclick="this.value='';" onfocus="this.select()" onblur="this.value=!this.value?'Type a name for your team':this.value;" value="Type a name for your team" size="40"></td><td>4 digit Team PIN: </td><td><input type="password" name="teamPin" size="4" maxlength="4"></td><td><input type="submit" class="submit long" name="act" value="Create Team"></td>


<?php
  $result = mysql_query("SELECT id, name FROM teams order by name ASC");
  $options="";

 while ($row=mysql_fetch_array($result)) {
 $id=$row["id"];
 $event=$row["name"];
 $options .="<OPTION VALUE=\"$id\">".$event."</OPTION>";
 } 
?>
<tr><td>Team Name: </td><td><SELECT NAME=teamId><OPTION VALUE=0>Choose a Team<?php echo $options ?></SELECT></td><td>4 digit Team PIN: </td><td><input type="password" name="teamauthPin" size="4" maxlength="4"></td><td><input type="submit" class="submit long" name="act" value="Join Team"></td> 

                        <tr><td>4 digit PIN: </td><td><input type="password" name="authPin" size="4" maxlength="4"><font size="1"> [The 4 digit PIN you chose when registering]</font></td></tr>
		</form>
<?
}else{
  $result = mysql_query("SELECT t.name as TeamName FROM webUsers as w, teams as t where w.id =".$userId." AND t.id = w.teamId");
$resultrow = mysql_fetch_object($result); 
 $team = $resultrow->TeamName;
?> 
                <form action="/accountdetails" method="post"><input type="hidden" name="act" value="teamDetails">
<tr><td>Team Name: </td><td><? echo $team?></td></tr><tr><td>4 digit PIN: </td><td><input type="password" name="authPin" size="4" maxlength="4"></td><td><input type="submit" class="submit long" name="act" value="Leave Team"></td></tr>
</form>
<?
}
?> 
               </table>
*/?>
	<!-- Cash Out -->
                <div class="block" style="clear:none;">
                 <div class="block_head">
                  <div class="bheadl"></div>
                  <div class="bheadr"></div>
                  <h2>Cash Out</h2>
                 </div>
                 <div class="block_content" style="padding:10px;">
		<ul><li><font color="">Please note: a 0.005 btc transaction will apply when processing "On-Demand" manual payments</font></li></ul>
		<form action="/accountdetails" method="post">
		<input type="hidden" name="act" value="cashOut">
		<table>
			<tr><td>Account Balance: &nbsp;&nbsp;&nbsp;</td><td><?php echo antiXss($currentBalance); ?> BTC</td></tr>
			<tr><td>Payout to: </td><td><h6><?php echo antiXss($paymentAddress); ?></h6></td></tr>
			<tr><td>4 digit PIN: </td><td><input type="password" name="authPin" size="4" maxlength="4"></td></tr>
		</table>
		<input type="submit" class="submit mid" value="Cash Out"></form>
                </div>          <!-- nested block ends -->
                <div class="bendl"></div>
                <div class="bendr"></div>
                </div>

	<!-- Change password -->
                <div class="block" style="clear:none;">
                 <div class="block_head">
                  <div class="bheadl"></div>
                  <div class="bheadr"></div>
                  <h2>Change Password</h2>
                 </div>
		<div class="block_content" style="padding:10px;">
		<ul><li><font color="">Note: You will be redirected to login on successful completion of a password change</font></li></ul>
		<form action="/accountdetails" method="post"><input type="hidden" name="act" value="updatePassword">
		<table>
			<tr><td>Current Password: </td><td><input type="password" name="currentPassword"></td></tr>
			<tr><td>New Password: </td><td><input type="password" name="newPassword"></td></tr>
			<tr><td>New Password Repeat: </td><td><input type="password" name="newPassword2"></td></tr>
			<tr><td>4 digit PIN: </td><td><input type="password" name="authPin" size="4"	maxlength="4"></td></tr>
		</table>
		<input type="submit" class="submit long" value="Change Password"></form>
                </div>          <!-- nested block ends -->
                <div class="bendl"></div>
                <div class="bendr"></div>
                </div>

          </div>          <!-- .sidebar_content ends -->

        </div>          <!-- .block_content ends -->

   <div class="bendl"></div>
   <div class="bendr"></div>

</div>          <!-- .block ends -->

<?php include ("includes/templates/footer.php");?>
