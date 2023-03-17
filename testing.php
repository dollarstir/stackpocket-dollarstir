
<?php

// handles the individual game functions handles the individual game functions called in front end
class gamefunctions
{


    public function gamerules()
    {
        // connecting to redis server
        require __DIR__ . '/../vendor/autoload.php';
        $redis = new Predis\client();

        $cachedgamerules =  $redis->get('gamerules');
        $cachedgamerulestoken =  $redis->get('gamerulestoken');
        $select = new select();
        $dbgameruletoken = $select->fetch('redis_worker', [['tableid', '=', '4']]);
        $dbgameruletoken = $dbgameruletoken[0]['token'];

        if ($cachedgamerules) {
            if ($cachedgamerulestoken == $dbgameruletoken) {
                // echo 'from cache';
                $res = json_decode($cachedgamerules, true);
            } else {
                // echo 'from db';
                $res = $select->fetchall('game_name');

                $redis->set('gamerules', json_encode($res));
                $redis->set('gamerulestoken', $dbgameruletoken);
            }
        } else {
            // echo 'from db';
            $res = $select->fetchall('game_name');
            $redis->set('gamerules', json_encode($res));
            $redis->set('gamerulestoken', $dbgameruletoken);
        }

        // making the gameid key and gamerule value from the array
        $gamerule = [];
        foreach ($res as $key => $value) {
            $gamerule[$value['gn_id']] = $value['guide'];
        }






        return $gamerule;
    }

    // seding bet data from user to database
    public function sendbetdata($data)
    {

        // Instantiate required classes
        $group = new all5groups();
        $insert = new insert();
        $select = new select();
        $all5 = new all5();
        $check = new checker();
        $generator = new generate();
        $update = new updates();
        $checker = new checker();

        // Decode JSON data

        $data = json_decode($data, true);
        $counter = 0;
        // Check if user is logged in
        if (!isset($_SESSION['betuser'])) {
            http_response_code(401);

            return ['title' => 'error', 'message' => 'not logged in'];
        } else {

            $overalstake = 0;
            // Calculate total bet amount across all bets
            foreach ($data as $key) {
                $overalstake += $key['totalBetAmt'];
            }
            // Check if user has sufficient balance to place the bets


            $bal = $checker->getuserbalance($_SESSION['betuser']['uid']);
            $bal = $bal['userBalance'];

            if ($overalstake > $bal) {
                http_response_code(402);

                return (['title' => 'error', 'message' => 'Insufficient balance']);
            } else {
                // Get next draw time from API
                // $nextdraw = $generator->nextdrawfromapi();

                foreach ($data as $key) {

                    // Extract bet data

                    $uid = $_SESSION['betuser']['uid'];
                    $selectiondata = $key['allSelections'];
                    $user_selection = $key['userSelections'];
                    $game_id = $key['gameId'];
                    $gamed = $select->fetch('game_name', [['gn_id', '=', $game_id]]);
                    $gameodds =  $gamed[0]['odds'];
                    $bet_amount = $key['totalBetAmt'];
                    $totalBets = $key['totalBets'];
                    $multiplier = $key['multiplier'];
                    $unitStake = $key['unitStaked'];
                    $winningAmount = ($gameodds *  $unitStake * $multiplier);
                    // Generate unique bet code
                    $btcount = $generator->getlastbetcount();
                    $btcount = $btcount + 1;

                    if ($btcount < 10) {
                        $btcount = '000' . $btcount;
                    } elseif ($btcount < 100) {
                        $btcount = '00' . $btcount;
                    } elseif ($btcount < 1000) {
                        $btcount = '0' . $btcount;
                    }
                    $betcount = $generator->countallbet();
                    $betcount = $betcount + 1;
                    $betcode = 'ASD' . rand(11111, 99999) . $betcount;

                    $datef = date('Y-m-d');
                    $newissueno = date('Ymd', strtotime($datef));
                    $newissueno = $newissueno . '-' . $btcount;

                    // Fetch game data
                    $game_m = $select->fetch('game_name', [['gn_id', '=', $game_id]],);
                    $game_name = $game_m[0]['name'];
                    $gg = $select->fetch('game_group', [['gp_id', '=', $game_m[0]['game_group']]],);
                    $game_group = $gg[0]['name'];
                    $gt = $select->fetch('game_type', [['gt_id', '=', $gg[0]['game_type']]],);
                    $game_type = $gt[0]['name'];


                    $record = [
                        'game_type' => $game_type,
                        'game_group' => $game_group,
                        'game_name' => $game_name,
                        'game_id' => $game_id,
                        'user_selection' => $user_selection,
                        'selection_group' => serialize($selectiondata),
                        'uid' => $uid,
                        'balance_before' => $bal,
                        'balance_after' => $bal - $bet_amount,
                        'email' => $_SESSION['betuser']['user_email'],
                        'mobile' => $_SESSION['betuser']['user_contact'],
                        'bet_amount' => $bet_amount,
                        'win_bonus' => $winningAmount,
                        'bet_odds' => $gameodds,
                        'unit_stake' => $unitStake,
                        'bet_number' => $totalBets,
                        'bet_status' => 'pending',
                        'bet_code' => $betcode,
                        'bet_date' => date('Y-m-d'),
                        'bet_time' => date('H:i:s'),
                        'bet_period' => $newissueno,
                        'state' => 'Not Settled',
                        'multiplier' => $multiplier,
                        'num_wins' => 0,
                        // 'draw_number' => $generator->getlastdrawnumber(),
                        // 'draw_time' => $generator->getlastdrawtime(),
                        'draw_period' => $key['betId'], //$nextdraw,
                        'ip_address' => $_SERVER['REMOTE_ADDR'],
                    ];

                    if ($insert->ins('bet', $record) == "success") {
                        $balance = $select->fetch('users', [['uid', '=', $uid]],);
                        $balance = $balance[0]['balance'];
                        $newbalance = $balance - $bet_amount;
                        $update->update('users', ['balance' => $newbalance], ['uid' => $uid]);
                        // adding record to transaction table
                        $insert->ins('transaction', ['uid' => $uid, 'amount' => $bet_amount, 'transaction_type' => 'debit', 'description' => 'Betting Deduct', 'date_created' => date('Y-m-d'),'dateTime'=>date('Y-m-d H:i:s'), 'ip_address' => $_SERVER['REMOTE_ADDR'], 'status' => 'success', 'balance' => $newbalance]);



                        $counter++;
                    }
                }
                if ($counter >= 1) {
                    // http_response_code(200);
                    $update->update('redis_worker', ['token' => uniqid('bet') . time()], ['tableid' => 1]);
                    return (['title' => 'success', 'message' => 'Bet Placed Successfully']);
                } else {
                    // http_response_code(400);
                    return (['title' => 'error', 'message' => 'Fialed to place bet']);
                }
            }
        }
    }


    // sending track  bet data from user to database

    public function sendTrackdata($data)
    {

        // $data = json_decode($data, true);
        // $group = new all5groups();
        // $insert = new insert();
        // $select = new select();
        // $all5 = new all5();
        // $check = new checker();
        // $generator = new generate();
        // $update = new updates();
        // $checker = new checker();

        // $counter = 0;

        // if(!isset($_SESSION['betuser'])){

        //    return(['title' => 'error', 'message' => 'not logged in']);

        // }
        // else{

        //     $token  = 'TKD'.uniqid();

        //     $overalstake = $data['trackInfo']['total_amt_to_pay'];

        //     $bal = $checker->getuserbalance($_SESSION['betuser']['uid']);
        //     $bal = $bal['userBalance'];

        //     if($overalstake > $bal){
        //        return(['title' => 'error', 'message' => 'Insufficient balance']);

        //     }
        //     else{

        //         foreach ($data['bets'] as $key) {

        //             $uid = $_SESSION['betuser']['uid'];
        //             $selectiondata = $data['trackInfo']['allSelections'];
        //             $user_selection = $data['trackInfo']['userSelections'];
        //             $game_id = $data['trackInfo']['gameId'];
        //             $gamed = $select->fetch('game_name',[['gn_id', '=', $game_id]],);
        //             $gameodds = $gamed[0]['odds'];

        //             $bet_amount = $key['betAmt'];
        //             $totalBets = $data['trackInfo']['totalBets'];
        //             $multiplier = $key['multiplier'];
        //             $unitStake = $data['trackInfo']['unitStaked'];
        //             $winningAmount = ($gameodds *  $unitStake * $multiplier);




        //             $btcount = $generator->getlastbetcount();
        //             $btcount = $btcount + 1;

        //             if ($btcount < 10) {
        //                 $btcount = '000'.$btcount;
        //             } elseif ($btcount < 100) {
        //                 $btcount = '00'.$btcount;
        //             } elseif ($btcount < 1000) {
        //                 $btcount = '0'.$btcount;
        //             }




        //             $betcount = $generator->countallbet();
        //             $betcount = $betcount + 1;
        //             $betcode = 'ASD'.rand(11111, 99999).$betcount;

        //             $datef = date('Y-m-d');
        //             $newissueno = date('Ymd', strtotime($datef));
        //             $newissueno = $newissueno.'-'.$btcount;

        //                 $game_m= $select->fetch('game_name', [['gn_id', '=', $game_id]],);
        //                 $game_name = $game_m[0]['name'];
        //                 $gg =$select->fetch('game_group', [['gp_id', '=', $game_m[0]['game_group']]],);
        //                 $game_group = $gg[0]['name'];
        //                 $gt =$select->fetch('game_type', [['gt_id', '=', $gg[0]['game_type']]],);
        //                 $game_type = $gt[0]['name']; 


        //             $record = [
        //                 'game_type' => $game_type,
        //                 'game_group' => $game_group,
        //                 'game_name' => $game_name,
        //                 'game_id' => $game_id,
        //                 'user_selection' => $user_selection,
        //                 'selection_group' => serialize($selectiondata),
        //                 'uid' => $uid,
        //                 'email' =>$_SESSION['betuser']['user_email'] ,
        //                 'mobile' => $_SESSION['betuser']['user_contact'],
        //                 'bet_amount' => $bet_amount,
        //                 'win_bonus' => $winningAmount,
        //                 'bet_odds' => $gameodds,
        //                 'unit_stake'=>$unitStake,
        //                 'bet_number' => $totalBets,
        //                 'bet_status' => 'pending',
        //                 'bet_code' => $betcode,
        //                 'bet_date' => date('Y-m-d'),
        //                 'bet_time' => date('H:i:s'),
        //                 'bet_period' => $newissueno,
        //                 'state' => 'Not Settled',
        //                 'multiplier' => $multiplier,
        //                 'token' => $token,
        //                 'stop_if_won'=>$data['trackInfo']['stop_if_win'],
        //                 'stop_if_lost'=>$data['trackInfo']['stop_if_not_win'],
        //                 'bettype'=>'track',
        //                 // 'draw_number' => $generator->getlastdrawnumber(),
        //                 // 'draw_time' => $generator->getlastdrawtime(),
        //                 'draw_period' => $key['betId'],
        //                 'ip_address' => $_SERVER['REMOTE_ADDR'], ];

        //                 if($insert->ins('bet', $record)=="success"){
        //                     $balance = $select ->fetch('users', [['uid', '=', $uid]],);
        //                     $balance = $balance[0]['balance'];
        //                     $newbalance = $balance - $bet_amount;
        //                     $update->update('users', ['balance' => $newbalance], ['uid'=>$uid]);


        //                     $counter++;
        //                 }




        //         }
        //         if($counter >= 1){
        //             $update ->update('redis_worker', ['token' =>uniqid('bet').time()], ['tableid' => 1]);
        //             return(['title' => 'success', 'message' => 'Bet Placed Successfully']);

        //         }
        //         else{
        //             return(['title' => 'error', 'message' => 'Fialed to place bet']);
        //         }

        //     }







        // }


        // new function for track


        // $data = file_get_contents('php://input');
        $data = json_decode($data, true);
        $group = new all5groups();
        $insert = new insert();
        $select = new select();
        $all5 = new all5();
        $check = new checker();
        $generator = new generate();
        $update = new updates();
        $checker = new checker();

        $counter = 0;

        if (!isset($_SESSION['betuser'])) {

            return (['title' => 'error', 'message' => 'not logged in']);
        } else {
            // check the overal amount to pay
            $overalstake = $data['trackInfo']['total_amt_to_pay'];
            // get the user balance
            $bal = $checker->getuserbalance($_SESSION['betuser']['uid']);
            $bal = $bal['userBalance'];
            //  // check if the user has enough balance
            if ($overalstake > $bal) {
                return (['title' => 'error', 'message' => 'Insufficient balance']);
            } else {

                // try to insert the bet into the database
                foreach ($data['trackData'] as $track) {
                    // token for each track
                    $token  = 'TKD' . uniqid();




                    // getting game data from database based on game id 
                    $game_id = $track['gameId'];
                    $gamed = $select->fetch('game_name', [['gn_id', '=', $game_id]],);

                    // getting game odds

                    $gameodds = $gamed[0]['odds'];


                    // other game data 

                    $game_m = $select->fetch('game_name', [['gn_id', '=', $game_id]],);
                    $game_name = $game_m[0]['name'];
                    $gg = $select->fetch('game_group', [['gp_id', '=', $game_m[0]['game_group']]],);
                    $game_group = $gg[0]['name'];
                    $gt = $select->fetch('game_type', [['gt_id', '=', $gg[0]['game_type']]],);
                    $game_type = $gt[0]['name'];

                    // check if the user has enough balance

                    foreach ($data['bets'] as $bet) {

                        $uid = $_SESSION['betuser']['uid'];
                        $selectiondata =  $track['allSelections'];
                        $user_selection = $track['userSelections'];
                        $bet_amount =  $bet['betAmt'];
                        $totalBets  = $track['totalBets'];
                        $multiplier = $bet['multiplier'];
                        $unitStake = $track['unitStaked'];
                        $winningAmount =  ($gameodds *  $unitStake * $multiplier);

                        $btcount = $generator->getlastbetcount();
                        $btcount = $btcount + 1;

                        if ($btcount < 10) {
                            $btcount = '000' . $btcount;
                        } elseif ($btcount < 100) {
                            $btcount = '00' . $btcount;
                        } elseif ($btcount < 1000) {
                            $btcount = '0' . $btcount;
                        }




                        $betcount = $generator->countallbet();
                        $betcount = $betcount + 1;
                        $betcode = 'ASD' . rand(11111, 99999) . $betcount;

                        $datef = date('Y-m-d');
                        $newissueno = date('Ymd', strtotime($datef));
                        $newissueno = $newissueno . '-' . $btcount;


                        // insert bet data into database

                        $record = [
                            'game_type' => $game_type,
                            'game_group' => $game_group,
                            'game_name' => $game_name,
                            'game_id' => $game_id,
                            'user_selection' => $user_selection,
                            'selection_group' => serialize($selectiondata),
                            'uid' => $uid,
                            'email' => $_SESSION['betuser']['user_email'],
                            'mobile' => $_SESSION['betuser']['user_contact'],
                            'bet_amount' => $bet_amount,
                            'win_bonus' => $winningAmount,
                            'bet_odds' => $gameodds,
                            'unit_stake' => $unitStake,
                            'bet_number' => $totalBets,
                            'bet_status' => 'pending',
                            'bet_code' => $betcode,
                            'bet_date' => date('Y-m-d'),
                            'bet_time' => date('H:i:s'),
                            'bet_period' => $newissueno,
                            'state' => 'Not Settled',
                            'multiplier' => $multiplier,
                            'token' => $token,
                            'stop_if_won' => $data['trackInfo']['stop_if_win'],
                            'stop_if_lost' => $data['trackInfo']['stop_if_not_win'],
                            'bettype' => 'track',
                            // 'draw_number' => $generator->getlastdrawnumber(),
                            // 'draw_time' => $generator->getlastdrawtime(),
                            'draw_period' => $bet['betId'],
                            'num_wins' => 0,
                            'ip_address' => $_SERVER['REMOTE_ADDR'],
                        ];

                        if ($insert->ins('bet', $record) == "success") {
                            $balance = $select->fetch('users', [['uid', '=', $uid]],);
                            $balance = $balance[0]['balance'];
                            $newbalance = $balance - $bet_amount;
                            $update->update('users', ['balance' => $newbalance], ['uid' => $uid]);

                            // adding record to transaction table
                            $insert->ins('transaction', ['uid' => $uid, 'amount' => $bet_amount, 'transaction_type' => 'debit', 'description' => 'Betting Deduct', 'date_created' => date('Y-m-d'),'dateTime'=>date('Y-m-d H:i:s'),'ip_address' => $_SERVER['REMOTE_ADDR'], 'status' => 'success', 'balance' => $newbalance]);



                            $counter++;
                        }




                        // echo "Draw Period: {$bet['betId']} || Multiplier : {$bet['multiplier']}  || Bet Amount : {$bet['betAmt']}  || Game ID :  {$track['gameId']}  UnitStake : {$track['unitStaked']}  ||  All Selections : " .json_encode($track['allSelections'])." || User Selections : {$track['userSelections']}  ||  Total Bet : {$track['totalBets']}   || stop_if_win : {$data['trackInfo']['stop_if_win']}  || stop_if_lost : {$data['trackInfo']['stop_if_not_win']}  <br>";


                    }
                }

                if ($counter >= 1) {
                    $update->update('redis_worker', ['token' => uniqid('bet') . time()], ['tableid' => 1]);
                    return (['title' => 'success', 'message' => 'Bet Placed Successfully']);
                } else {
                    return (['title' => 'error', 'message' => 'Fialed to place bet']);
                }
            }
        }
    }


    // Front End Records Section +++++++++++++++++++++++++++++++++++++++++++++++++++++++

    public function userbetrecords($uid, $data,$page = 1)
    {
        // Load Redis and Select classes
        require_once __DIR__ . '/../vendor/autoload.php';
        $redis = new Predis\client();
        $select = new Select();
        $filter = new datesearch();

        // Start the session
        // session_start();

        // check if user is logged in
        $data = trim($data);
        if (!isset($uid)) {
            return (['title' => 'error', 'message' => 'You are not logged in']);
        } else {


            // Check if the data is set
            switch ($data) {

                case 'all':
                    $newdata = 'all';
                    break;
                case 'today':
                    $newdata = date('Y-m-d');
                    break;
                case 'yesterday':
                    $newdata = date('Y-m-d', strtotime('-1 day'));
                    break;
                case 'twodays':
                    $newdata = date('Y-m-d', strtotime('-2 day'));
                    break;

                case 'thisweek':
                    $newdata = date('Y-m-d', strtotime('monday this week'));
                    break;
                case 'lastweek':
                    $newdata = date('Y-m-d', strtotime('monday last week'));
                    break;
                case 'twentydays':
                    $newdata = date('Y-m-d', strtotime('-20 day'));
                    break;
                case 'thismonth':
                    $newdata = date('Y-m-01');
                    break;
                case 'lastmonth':
                    $newdata = date('Y-m-01', strtotime('first day of last month'));
                    break;

                case 'twomonths':
                    $newdata = date('Y-m-01', strtotime('-2 months'));
                    break;

                case 'threemonths':
                    $newdata = date('Y-m-01', strtotime('-3 months'));
                    break;
                case 'thisyear':
                    $newdata = date('Y-01-01', strtotime('first day of this year'));
                    break;

                case 'lastyear':
                    $newdata = date('Y-01-01', strtotime('first day of last year'));
                    break;
                default:
                    $newdata = 'nothing';
                    break;
            }

            // Check if cached data exists
            $cachedData = $redis->get($data .''.$uid.'bets');
            $cachedDataToken = $redis->get($data.''.$uid. 'bets_token');

            // Fetch the token from the database for comparison
            $dbCachedToken = $select->fetch('redis_worker', [['tableid', '=', 1]])[0]['token'];

            // Set the condition for the database query
            $today = date('Y-m-d');
            $condition = ( $newdata == 'all') ? $select->fetch('bet', [['uid', '=', $uid]]) : $filter->dateresult('bet', ['uid' => $uid], 'bet_date', $newdata, $today, ['bid' => 'DESC']); // Otherwise, fetch the data from the database



            if($newdata == "nothing"){
                return ['title' => 'error', 'message' => 'Invalid Keyword'];
            }
            else
            {
                // If cached data exists and the token matches the one in the database, return the cached data
            if ($cachedData && $cachedDataToken && $cachedDataToken == $dbCachedToken) {
                // Decode the cached data and return it
                    $res = json_decode($cachedData, true);
                }
                else{
                  
                    // Otherwise, fetch the data from the database
                    $res = $condition;
                     // Store the fetched data in Redis cache and return it
                    $redis->set($data .''.$uid.'bets', json_encode($res));
                    $redis->set($data.''.$uid. 'bets_token', $dbCachedToken);
                }
                // // Otherwise, fetch the data from the database
    
            }

            if(empty($res)){
                return ['title' => 'error', 'message' => 'No Record Found'];
            } else {

                // Set the number of records per page
                $perPage = 10;

                // Determine the total number of records
                $totalRecords = count($res);

                // Calculate the total number of pages
                $totalPages = ceil($totalRecords / $perPage);

                // Use array_slice to extract records for the current page
                $currentPageRecords = array_slice($res, ($page - 1) * $perPage, $perPage);

                $links = [];
                for ($i = 1; $i <= $totalPages; $i++) {
                    $links[] = "<a href='http://192.168.199.126/task/apis/betrecords.php?data=${data}&page=$i'>$i</a>";
                }




                //  extracting needed fields from the array
                $neededfiled = ['bet_period','user_selection','win_bonus','num_wins','bet_date','bet_time','game_type','bet_amount','balance_before','balance_after','bet_status'];
                    // filtering the array
                    $result = array_map(function ($record) use ($neededfiled) {
                    return array_intersect_key($record, array_flip($neededfiled));
                },$currentPageRecords);

                if(empty($result)){
                    return ['title' => 'error', 'message' => 'No Record Found'];
                } else {
                    return ['title' => 'success', 'message' =>$result, 'links' => $links];
                }
                return ['title' => 'success', 'message' =>$result, 'links' => $links];
            }

       
            
        
        }
    }



    // Trackrecords Section +++++++++++++++++++++++++++++++++++++++++++++++++++++++

    public function usertrackrecords($uid, $data, $page=1)
    {
        // Load Redis and Select classes
        require_once __DIR__ . '/../vendor/autoload.php';
        $redis = new Predis\client();
        $select = new Select();
        $filter = new datesearch();

        
        // Start the session
        // session_start();

        // check if user is logged in
        $data = trim($data);
        if (!isset($uid)) {
            return (['title' => 'error', 'message' => 'You are not logged in']);
        } else {


            // Check if the data is set
            switch ($data) {

                case 'all':
                    $newdata = 'all';
                    break;
                case 'today':
                    $newdata = date('Y-m-d');
                    break;
                case 'yesterday':
                    $newdata = date('Y-m-d', strtotime('-1 day'));
                    break;
                case 'thisweek':
                    $newdata = date('Y-m-d', strtotime('monday this week'));
                    break;
                case 'lastweek':
                    $newdata = date('Y-m-d', strtotime('monday last week'));
                    break;
                case 'twentydays':
                    $newdata = date('Y-m-d', strtotime('-20 days'));
                    break;
                case 'thismonth':
                    $newdata = date('Y-m-01');
                    break;
                case 'lastmonth':
                    $newdata = date('Y-m-01', strtotime('first day of last month'));
                    break;

                case 'twomonths':
                    $newdata = date('Y-m-01', strtotime('-2 months'));
                    break;

                case 'threemonths':
                    $newdata = date('Y-m-01', strtotime('-3 months'));
                    break;
                case 'thisyear':
                    $newdata = date('Y-01-01', strtotime('first day of this year'));
                    break;

                case 'lastyear':
                    $newdata = date('Y-01-01', strtotime('first day of last year'));
                    break;
                default:
                    $newdata = 'nothing';
                    break;
            }

            // Check if cached data exists
            $cachedData = $redis->get($data .''.$uid.'usertrack');
            $cachedDataToken = $redis->get($data.''.$uid. 'usertrack_token');

            // Fetch the token from the database for comparison
            $dbCachedToken = $select->fetch('redis_worker', [['tableid', '=', 1]])[0]['token'];

            // Set the condition for the database query
            
            $today = date('Y-m-d');
            $condition = ( $newdata == 'all') ? $select->fetch('bet', [['uid', '=', $uid],['bettype','=','track']],'AND') : $filter->dateresult('bet', ['uid' => $uid,'bettype'=>'track'], 'bet_date', $newdata, $today, ['bid' => 'DESC']); // Otherwise, fetch the data from the database



            if($newdata == "nothing"){
                return ['title' => 'error', 'message' => 'Invalid Keyword'];
            }
            else
            {
                // If cached data exists and the token matches the one in the database, return the cached data
                if ($cachedData && $cachedDataToken && $cachedDataToken == $dbCachedToken) {
                // Decode the cached data and return it
                    $res = json_decode($cachedData, true);
                }
                else{
                  
                    // Otherwise, fetch the data from the database
                    $res = $condition;
                     // Store the fetched data in Redis cache and return it
                    $redis->set($data .''.$uid.'usertrack', json_encode($res));
                    $redis->set($data.''.$uid. 'usertrack_token', $dbCachedToken);
                }
                // // Otherwise, fetch the data from the database

    
            }

            if(empty($res)){
                return ['title' => 'error', 'message' => 'No Record Found'];
            } else {

                $betdata = [];
              

                foreach($res as $value){
                    $bettoken = $value['token'];
                    $count =  new count();
                    $drawcount = $count->count('bet', [['token','=',$bettoken],['state','=','Settled']],'AND');
                    $trackcount = $count->count('bet', [['token','=', $bettoken],['bettype','=','track']],'AND');
                    $sum  = new sums();
                    $investedAmount = $sum->sum('bet','bet_amount', [['token','=',$bettoken],['state','=','Settled']],'AND');
                    $totalstakeamount= $sum->sum('bet','bet_amount', [['token','=',$bettoken]],'AND');
                    $investedttoal = $investedAmount.'/'.$totalstakeamount;
                    $chased = $drawcount.'/'.$trackcount;
                    $wonAmount = $sum->sum('bet','win_bonus', [['token','=',$bettoken],['state','=','Settled'],['bet_status','=','won']],'AND');
                    $startperiod = $select->fetch('bet', [['token','=',$bettoken],['bettype','=','track']],'AND',['bid'=>'ASC'],1 );
                    $endperiod = $select->fetch('bet', [['token','=',$bettoken],['bettype','=','track']],'AND',['bid'=>'DESC'],1 );
                    $trackstatus = ($drawcount == $trackcount) ? 'Done' : 'In Progress';
                    $record =  [
                        'game_type' => $value['game_type'],
                        'game_name' => $value['game_name'],
                        'start_period' => $startperiod[0]['bet_date'],
                        'total_draw'=>$trackcount,
                        'tracked'=>$drawcount,
                        'total_amount'=>$totalstakeamount,
                        'Prize'=>$wonAmount,
                        'status'=>$trackstatus,
                        'bet_token'=>$bettoken,

                        
                        
                    ];
                    // check if bet token is already in the array (betdata)
                    if (!in_array($bettoken, array_column($betdata, 'bet_token'))) {
                        array_push($betdata, $record);
                    }

                     // Set the number of records per page
                    $perPage = 10;

                    // Determine the total number of records
                    $totalRecords = count($betdata);

                    // Calculate the total number of pages
                    $totalPages = ceil($totalRecords / $perPage);

                    // Use array_slice to extract records for the current page
                    $currentPageRecords = array_slice($betdata, ($page - 1) * $perPage, $perPage);

                    $links = [];
                    for ($i = 1; $i <= $totalPages; $i++) {
                        $links[] = "<a href='http://192.168.199.126/task/apis/usertrack.php?data=${data}&page=$i'>$i</a>";
                    }
                        
                        
                    }

                if(empty($res)){
                    return ['title' => 'error', 'message' => 'No Record Found'];
                } else {
                    return ['user' => $_SESSION['betuser']['uid'], 'title' => 'success', 'message' =>$currentPageRecords, 'links' => $links];
                }
                // return ['user' => $_SESSION['betuser']['uid'], 'title' => 'success', 'message' =>$betdata];
            }

       
            
        
        }
    }



    // transaction settings(Bonus)************************************************************************
    public function bonus($uid, $data,$page=1 ){
        $redis = new Predis\client();
        $select = new Select();
        $filter = new datesearch();

       
        // check if user is logged in
        $data = trim($data);
        if (!isset($uid)) {
            return (['title' => 'error', 'message' => 'You are not logged in']);
        } else {

            switch ($data) {

                case 'all':
                    $newdata = 'all';
                    break;
                case 'today':
                    $newdata = date('Y-m-d');
                    break;
                case 'yesterday':
                    $newdata = date('Y-m-d', strtotime('-1 day'));
                    break;
                case 'thisweek':
                    $newdata = date('Y-m-d', strtotime('monday this week'));
                    break;
                case 'lastweek':
                    $newdata = date('Y-m-d', strtotime('monday last week'));
                    break;

                case 'twentydays':
                    $newdata = date('Y-m-d', strtotime('-20 days'));
                    break;
                case 'thismonth':
                    $newdata = date('Y-m-01');
                    break;
                case 'lastmonth':
                    $newdata = date('Y-m-01', strtotime('first day of last month'));
                    break;

                case 'twomonths':
                    $newdata = date('Y-m-01', strtotime('-2 months'));
                    break;

                case 'threemonths':
                    $newdata = date('Y-m-01', strtotime('-3 months'));
                    break;
                case 'thisyear':
                    $newdata = date('Y-01-01', strtotime('first day of this year'));
                    break;

                case 'lastyear':
                    $newdata = date('Y-01-01', strtotime('first day of last year'));
                    break;
                default:
                    $newdata = 'nothing';
                    break;
            }

            // Check if cached data exists
            $cachedData = $redis->get($data .''.$uid.'userbonus');
            $cachedDataToken = $redis->get($data.''.$uid. 'userbonus_token');

            // Fetch the token from the database for comparison
            $dbCachedToken = $select->fetch('redis_worker', [['tableid', '=', 14]])[0]['token'];

            // Set the condition for the database query
            
            $today = date('Y-m-d');
            $condition = ( $newdata == 'all') ? $select->fetch('transaction', [['uid', '=', $uid]],'AND') : $filter->dateresult('transaction', ['uid' => $uid], 'bet_date', $newdata, $today, ['bid' => 'DESC']); // Otherwise, fetch the data from the database



            if($newdata == "nothing"){
                return ['title' => 'error', 'message' => 'Invalid Keyword'];
            }
            else{

                     // If cached data exists and the token matches the one in the database, return the cached data
                    if ($cachedData && $cachedDataToken && $cachedDataToken == $dbCachedToken) {
                    // Decode the cached data and return it
                        $res = json_decode($cachedData, true);
                    }
                    else{
                      
                        // Otherwise, fetch the data from the database
                        $res = $condition;
                         // Store the fetched data in Redis cache and return it
                        $redis->set($data .''.$uid.'userbonus', json_encode($res));
                        $redis->set($data.''.$uid. 'userbonus_token', $dbCachedToken);
                    }
                    
                    if(empty($res)){
                        return ['title' => 'error', 'message' => 'No Record Found'];
                    } else {
                      
                        // Set the number of records per page
                    $perPage = 10;

                    // Determine the total number of records
                    $totalRecords = count($res);

                    // Calculate the total number of pages
                    $totalPages = ceil($totalRecords / $perPage);

                    // Use array_slice to extract records for the current page
                    $currentPageRecords = array_slice($res, ($page - 1) * $perPage, $perPage);

                    $links = [];
                    for ($i = 1; $i <= $totalPages; $i++) {
                        $links[] = "<a href='http://192.168.199.126/task/apis/bonus.php?data=${data}&page=$i'>$i</a>";
                    }

                    if(empty($res)){
                        return ['title' => 'error', 'message' => 'No Record Found'];
                    } else {
                        return ['user' => $_SESSION['betuser']['uid'], 'title' => 'success', 'message' =>$currentPageRecords, 'links' => $links];
                    }
                    }




            }


        }
    }
}
