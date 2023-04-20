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