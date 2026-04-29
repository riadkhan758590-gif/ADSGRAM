<?php
// =========================== PHP BACKEND ===========================
$settings_file = __DIR__ . '/setting.json'; 
$user_dir      = __DIR__ . '/user';         
$user_file     = $user_dir . '/user.json';  

$admin_chat_id = "8216085439"; // ✅ আপনার অ্যাডমিন আইডি

// ১. সেটিংস ফাইল তৈরি (না থাকলে)
$default_settings =[
    'currency' => 'BDT', 
    'dailyBonusAmount' => 0.10,
    'adRewardAmount' => 0.10,
    'dailyAdLimit' => 10,
    'withdrawMethods' => 'bKash:200, Nagad:200, Rocket:200, Binance:5', 
    'adsgramBlockId' => '28773',
    'botToken' => '' // ⚠️ অ্যাডমিন প্যানেল থেকে বট টোকেন দিতে হবে
];

if (!file_exists($settings_file)) {
    @file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}

if (!is_dir($user_dir)) { @mkdir($user_dir, 0777, true); }
if (!file_exists($user_file)) { @file_put_contents($user_file, json_encode([], JSON_PRETTY_PRINT)); }

$settings = @json_decode(@file_get_contents($settings_file), true);
if (!is_array($settings)) { $settings = $default_settings; }

$users = @json_decode(@file_get_contents($user_file), true);
if (!is_array($users)) { $users =[]; }

// ৪. API রিকোয়েস্ট হ্যান্ডেল করা
if (isset($_GET['action'])) {
    error_reporting(0);
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true) ?:[];

    if ($action == 'sync_user') {
        $user_id = isset($input['id']) ? (string)$input['id'] : 'guest';
        
        if (!isset($users[$user_id])) {
            $users[$user_id] =[
                'id' => $user_id,
                'firstName' => $input['firstName'] ?? $input['first_name'] ?? 'Unknown',
                'lastName' => $input['lastName'] ?? $input['last_name'] ?? '',
                'username' => $input['username'] ?? '',
                'photoUrl' => $input['photoUrl'] ?? $input['photo_url'] ?? '',
                'balance' => 0.00,
                'adsWatched' => 0,
                'lifetimeEarned' => 0.00,
                'lastBonusDate' => '',
                'withdrawHistory' =>[]
            ];
            @file_put_contents($user_file, json_encode($users, JSON_PRETTY_PRINT));
        }

        $today = date('Y-m-d');
        $bonusClaimed = ($users[$user_id]['lastBonusDate'] === $today);

        $response =[
            'user' => $users[$user_id],
            'bonusClaimed' => $bonusClaimed,
            'settings' => $settings
        ];

        if ($user_id === $admin_chat_id) { $response['all_users'] = $users; }
        echo json_encode($response);
        exit;
    }

    if ($action == 'add_reward') {
        $user_id = isset($input['id']) ? (string)$input['id'] : '';
        $type = $input['type'] ?? '';

        if ($user_id && isset($users[$user_id])) {
            $today = date('Y-m-d');
            if ($type == 'ad') {
                $users[$user_id]['balance'] += (float)$settings['adRewardAmount'];
                $users[$user_id]['lifetimeEarned'] += (float)$settings['adRewardAmount'];
                $users[$user_id]['adsWatched'] += 1;
            } elseif ($type == 'bonus') {
                if ($users[$user_id]['lastBonusDate'] !== $today) {
                    $users[$user_id]['balance'] += (float)$settings['dailyBonusAmount'];
                    $users[$user_id]['lifetimeEarned'] += (float)$settings['dailyBonusAmount'];
                    $users[$user_id]['lastBonusDate'] = $today;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Already claimed']);
                    exit;
                }
            }
            @file_put_contents($user_file, json_encode($users, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'user' => $users[$user_id]]);
            exit;
        }
    }

    if ($action == 'withdraw') {
        $user_id = isset($input['id']) ? (string)$input['id'] : '';
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
        $method = $input['method'] ?? 'Unknown';
        $address = $input['address'] ?? '';
        
        if ($user_id && isset($users[$user_id]) && $users[$user_id]['balance'] >= $amount && $amount > 0) {
            $users[$user_id]['balance'] -= $amount;
            
            if(!isset($users[$user_id]['withdrawHistory'])) { $users[$user_id]['withdrawHistory'] = []; }
            $users[$user_id]['withdrawHistory'][] =[
                'date' => date('d M Y, h:i A'),
                'amount' => $amount,
                'method' => $method,
                'address' => $address,
                'status' => 'Pending'
            ];

            @file_put_contents($user_file, json_encode($users, JSON_PRETTY_PRINT));

            // PHP থেকে টেলিগ্রাম নোটিফিকেশন পাঠানো
            $botToken = $settings['botToken'] ?? '';
            if (!empty($botToken)) {
                $currency = $settings['currency'] ?? 'BDT';
                
                // 🚀 টেলিগ্রাম মেসেজেও [Amount Space Currency] ফরম্যাট করা হলো
                $formatted_amount = number_format($amount, 2) . " " . $currency; 

                $msg = "🚨 *New Withdraw Request*\n\n";
                $msg .= "👤 *Name:* " . $users[$user_id]['firstName'] . "\n";
                $msg .= "🆔 *ID:* `" . $user_id . "`\n";
                $msg .= "💵 *Amount:* " . $formatted_amount . "\n";
                $msg .= "🏦 *Method:* " . $method . "\n";
                $msg .= "📍 *Account:* `" . $address . "`";

                $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
                $data =[
                    'chat_id' => $admin_chat_id,
                    'text' => $msg,
                    'parse_mode' => 'Markdown'
                ];

                $options = [
                    'http' =>[
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/json\r\n",
                        'content' => json_encode($data)
                    ]
                ];
                $context = stream_context_create($options);
                @file_get_contents($url, false, $context); 
            }

            echo json_encode(['success' => true, 'user' => $users[$user_id]]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Insufficient balance or invalid amount']);
        exit;
    }

    if ($action == 'update_settings') {
        if (isset($input['admin_id']) && (string)$input['admin_id'] === $admin_chat_id) {
            $settings = $input['settings'];
            @file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($action == 'edit_balance') {
        if (isset($input['admin_id']) && (string)$input['admin_id'] === $admin_chat_id) {
            $target = $input['target_user'];
            $new_bal = (float)$input['new_balance'];
            if(isset($users[$target])) {
                $users[$target]['balance'] = $new_bal;
                @file_put_contents($user_file, json_encode($users, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }

    // 🚀 NEW: Withdraw Status Update Handle
    if ($action == 'update_withdraw_status') {
        if (isset($input['admin_id']) && (string)$input['admin_id'] === $admin_chat_id) {
            $target = $input['target_user'];
            $index = (int)$input['index'];
            $new_status = $input['status'];

            if(isset($users[$target]) && isset($users[$target]['withdrawHistory'][$index])) {
                $old_status = $users[$target]['withdrawHistory'][$index]['status'];
                $users[$target]['withdrawHistory'][$index]['status'] = $new_status;
                
                // যদি Cancel করা হয় এবং আগে পেন্ডিং ছিল, তাহলে ব্যালেন্স ফেরত দেওয়া
                if ($new_status === 'Cancelled' && $old_status === 'Pending') {
                    $users[$target]['balance'] += (float)$users[$target]['withdrawHistory'][$index]['amount'];
                }

                @file_put_contents($user_file, json_encode($users, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
}
?>

<!-- =========================== HTML FRONTEND =========================== -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
  <title>Premium Task App</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  
  <style>
    :root { 
        --bg-body: #050b14; 
        --bg-surface: rgba(16, 25, 43, 0.6); 
        --bg-surface-light: rgba(26, 38, 65, 0.8); 
        --border-color: rgba(255, 255, 255, 0.08); 
        --accent-primary: #00d2ff; 
        --accent-secondary: #3a7bd5; 
        --accent-success: #00f260; 
        --accent-warning: #f7b733; 
        --accent-danger: #fc4a1a; 
        --text-main: #ffffff; 
        --text-muted: #a0aec0; 
    }
    body { background-color: var(--bg-body); background-image: radial-gradient(circle at top right, rgba(58,123,213,0.15), transparent 400px), radial-gradient(circle at bottom left, rgba(0,210,255,0.1), transparent 400px); color: var(--text-main); font-family: 'Outfit', sans-serif; padding-bottom: 110px; min-height: 100vh; }
    
    .text-muted { color: var(--text-muted) !important; }
    .text-secondary { color: #cbd5e1 !important; }
    
    .premium-card { background: var(--bg-surface-light); backdrop-filter: blur(16px); border: 1px solid var(--border-color); border-radius: 20px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); transition: transform 0.3s ease; }
    
    .premium-input { background: rgba(0, 0, 0, 0.25) !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; color: white !important; border-radius: 14px; padding: 14px 18px; }
    .premium-input:focus { border-color: var(--accent-primary) !important; box-shadow: 0 0 0 3px rgba(0, 210, 255, 0.2) !important; }
    .premium-input::placeholder { color: rgba(255, 255, 255, 0.4) !important; }
    
    .btn-primary-custom { background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary)); color: #fff; border: none; border-radius: 14px; padding: 16px 24px; font-weight: 600; box-shadow: 0 6px 20px rgba(58, 123, 213, 0.4); transition: 0.3s; }
    .btn-primary-custom:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
    .btn-outline-custom { background: transparent; color: var(--accent-primary); border: 2px solid var(--accent-primary); border-radius: 12px; padding: 10px 20px; font-weight: 600; transition: 0.3s; }
    
    .top-nav { background: rgba(5, 11, 20, 0.85); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-color); padding: 15px 0; position: sticky; top: 0; z-index: 1000; }
    .profile-pic { width: 50px; height: 50px; object-fit: cover; border-radius: 50%; border: 2px solid var(--accent-primary); background: #1a2641; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.3rem; color: var(--accent-primary); padding: 2px; }
    .profile-pic img { border-radius: 50%; width: 100%; height: 100%; object-fit: cover; }
    
    .metric-box { background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 15px; text-align: center; }
    .progress-slim { height: 8px; background-color: rgba(255,255,255,0.1); border-radius: 10px; overflow: hidden; }
    .progress-slim .progress-bar { background: linear-gradient(90deg, var(--accent-secondary), var(--accent-primary)); }
    
    .app-bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(16, 25, 43, 0.95); backdrop-filter: blur(25px); border-top: 1px solid var(--border-color); display: flex; justify-content: space-around; align-items: center; height: 85px; z-index: 999; border-radius: 30px 30px 0 0; }
    .nav-item { text-decoration: none; display: flex; flex-direction: column; align-items: center; color: var(--text-muted); font-size: 0.85rem; flex: 1; cursor: pointer; transition: 0.3s; }
    .nav-item i { font-size: 1.5rem; margin-bottom: 4px; transition: 0.3s; }
    .nav-item.active { color: var(--accent-primary); font-weight: 600; }
    .nav-item.active i { transform: translateY(-4px); text-shadow: 0 0 15px var(--accent-primary); }
    
    .chip { background: rgba(0, 210, 255, 0.1); border: 1px solid rgba(0, 210, 255, 0.3); padding: 6px 14px; border-radius: 30px; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; color: var(--accent-primary); font-weight: 600;}
    
    .history-item { background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); padding: 12px; border-radius: 12px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .badge-pending { background: rgba(247, 183, 51, 0.15); color: var(--accent-warning); padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; border: 1px solid rgba(247, 183, 51, 0.3); }
    .badge-completed { background: rgba(0, 242, 96, 0.15); color: var(--accent-success); padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; border: 1px solid rgba(0, 242, 96, 0.3); }
    .badge-cancelled { background: rgba(252, 74, 26, 0.15); color: var(--accent-danger); padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; border: 1px solid rgba(252, 74, 26, 0.3); }

    .toast-area { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 5000; width: 90%; max-width: 350px; }
    .toast-pop { background: var(--bg-surface-light); backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; align-items: center; gap: 12px; opacity: 0; transition: 0.3s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 10px; }
    .toast-pop.success i { color: var(--accent-success); } .toast-pop.danger i { color: var(--accent-danger); } .toast-pop.warning i { color: var(--accent-warning); }

    .loading-spinner { background: var(--bg-body); z-index: 9999; }
    
    .custom-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 6000; display: none; align-items: center; justify-content: center; }
    .custom-modal { background: var(--bg-surface-light); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; width: 90%; max-width: 350px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
  </style>
</head>
<body>

  <!-- Loading Spinner -->
  <div class="loading-spinner position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" id="loading-spinner">
    <div class="spinner-border text-info" style="width: 3rem; height: 3rem;" role="status"></div>
  </div>

  <!-- Edit Balance Modal -->
  <div class="custom-modal-overlay" id="editModalOverlay">
    <div class="custom-modal">
        <h5 class="text-white mb-3">Edit User Balance</h5>
        <input type="hidden" id="editUid">
        <div class="mb-3">
            <label class="text-muted small mb-1">New Balance</label>
            <input type="number" step="0.01" id="editBalInput" class="form-control premium-input">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-secondary w-50 rounded-3" onclick="closeEditModal()">Cancel</button>
            <button class="btn btn-info w-50 rounded-3 text-dark fw-bold" onclick="saveEditedBalance()">Save</button>
        </div>
    </div>
  </div>

  <nav class="top-nav shadow-sm">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <div class="profile-pic" id="headerProfilePic"><i class="bi bi-person"></i></div>
        <div class="lh-1">
          <div class="text-muted small mb-1">Hello, Tasker</div>
          <h6 class="mb-0 fw-bold text-white UserName" id="headerUserName">Loading...</h6>
        </div>
      </div>
      <div>
        <div class="chip">
          <i class="bi bi-wallet2"></i> <span class="user-balance" id="globalBalance">0.00</span>
        </div>
      </div>
    </div>
  </nav>

  <main class="container py-4">
    <!-- HOME SECTION -->
    <section id="home">
      <div class="premium-card p-4 mb-4 text-center" style="background: linear-gradient(145deg, rgba(26, 38, 65, 0.9), rgba(16, 25, 43, 0.9));">
        <h6 class="text-muted fw-medium mb-1">Total Available Balance</h6>
        <h1 class="fw-bold mb-0 text-white user-balance display-4" id="homeBalance">0.00</h1>
      </div>

      <div class="premium-card p-3 mb-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <div class="bg-warning bg-opacity-10 p-2 rounded-circle text-warning fs-3 lh-1"><i class="bi bi-gift"></i></div>
          <div>
            <h6 class="text-white fw-bold mb-0">Daily Bonus</h6>
            <small class="text-muted" id="daily-bonus-text">Get free cash daily</small>
          </div>
        </div>
        <button id="claim-daily-bonus" class="btn btn-sm btn-outline-custom">Claim</button>
      </div> 

      <div class="row g-2 mb-4">
        <div class="col-4"><div class="metric-box"><i class="bi bi-bullseye text-info fs-4 d-block mb-1"></i><small class="text-muted d-block">Limit</small><strong class="fs-5 text-white taskCount">0</strong></div></div>
        <div class="col-4"><div class="metric-box"><i class="bi bi-check2-circle text-success fs-4 d-block mb-1"></i><small class="text-muted d-block">Done</small><strong class="fs-5 text-white tasksCompleted">0</strong></div></div>
        <div class="col-4"><div class="metric-box"><i class="bi bi-clock-history text-warning fs-4 d-block mb-1"></i><small class="text-muted d-block">Left</small><strong class="fs-5 text-white tasksRemaining">0</strong></div></div>
      </div>

      <div class="premium-card p-4">
        <div class="d-flex justify-content-between small text-muted mb-2">
            <span>Today's Target</span>
            <span id="progress-percent">0%</span>
        </div>
        <div class="progress progress-slim mb-4">
          <div class="progress-bar" id="progressBarFill" style="width: 0%"></div>
        </div>
        <button id="show-ad" class="btn btn-primary-custom w-100 fs-5">
          <i class="bi bi-play-circle me-2"></i> Start Earning Task
        </button>
      </div>
    </section>

    <!-- WITHDRAW SECTION -->
    <section class="d-none" id="withdraw">
      <div class="premium-card p-4 mb-4 text-center">
        <i class="bi bi-bank text-info display-4 mb-2 d-block"></i>
        <h4 class="fw-bold text-white mb-1">Withdraw Funds</h4>
        <p class="text-muted mb-0">Current: <span class="user-balance fw-bold text-white">0.00</span></p>
      </div>
      
      <div class="premium-card p-4">
        <form id="withdraw-form">
          <div class="mb-3">
            <label class="text-muted small mb-1">Select Payment Method</label>
            <select class="form-select premium-input" id="payment-method" required onchange="updateMinLimit()"></select>
            <small class="text-info mt-1 d-block" id="min-limit-text"></small>
          </div>
          <div class="mb-3">
            <label class="text-muted small mb-1">Amount</label>
            <input type="number" step="0.01" class="form-control premium-input" id="withdraw-amount" placeholder="Enter amount" required />
          </div>
          <div class="mb-4">
            <label class="text-muted small mb-1">Account Number / Address</label>
            <input type="text" class="form-control premium-input" id="withdraw-address" placeholder="Enter details..." required />
          </div>
          <button type="button" class="btn btn-primary-custom w-100" id="submitWithdrawBtn">Submit Request</button>
        </form>
      </div>
    </section>

    <!-- PROFILE SECTION -->
    <section class="d-none" id="profile">
      <div class="premium-card p-4 text-center mb-4">
        <div class="profile-pic mx-auto mb-3" id="profileLargeAvatar" style="width: 80px; height: 80px; font-size: 2rem;"><i class="bi bi-person"></i></div>
        <h5 class="text-white fw-bold mb-0 UserName">Loading...</h5>
        <div class="text-muted small mb-3" id="profileUserUsername">@user</div>
        
        <div class="row g-2 text-start">
            <div class="col-6"><div class="bg-dark bg-opacity-50 p-3 rounded-4 border border-secondary border-opacity-25"><small class="text-muted d-block">Total Earned</small><strong class="text-success fs-5" id="lifetimeEarning">0.00</strong></div></div>
            <div class="col-6"><div class="bg-dark bg-opacity-50 p-3 rounded-4 border border-secondary border-opacity-25"><small class="text-muted d-block">Ads Watched</small><strong class="text-info fs-5" id="adsWatchedCount">0</strong></div></div>
        </div>
      </div>

      <h6 class="text-muted mb-3 px-2"><i class="bi bi-clock-history me-2"></i>Withdraw History</h6>
      <div id="withdraw-history-list"></div>
    </section>

    <!-- ADMIN PANEL SECTION -->
    <section class="d-none" id="admin-panel">
      <div class="premium-card p-4 mb-4 text-center border-danger border-opacity-50">
        <i class="bi bi-shield-lock text-danger display-5 mb-2 d-block"></i>
        <h4 class="fw-bold text-white mb-0">Admin Access</h4>
      </div>

      <div class="premium-card p-4 mb-4">
        <h6 class="text-white mb-3"><i class="bi bi-sliders me-2 text-info"></i> App Configuration</h6>
        <form id="admin-settings-form">
          <div class="row g-3 mb-3">
            <div class="col-12">
              <label class="text-muted small mb-1">App Currency (e.g., BDT, ৳, USDT, $)</label>
              <input type="text" class="form-control premium-input border-info" id="admin-currency" placeholder="BDT" />
            </div>
            <div class="col-6">
              <label class="text-muted small mb-1">Daily Bonus</label>
              <input type="number" step="0.01" class="form-control premium-input" id="admin-daily-bonus" />
            </div>
            <div class="col-6">
              <label class="text-muted small mb-1">Ad Reward</label>
              <input type="number" step="0.01" class="form-control premium-input" id="admin-ad-reward" />
            </div>
          </div>
          <div class="mb-3">
            <label class="text-muted small mb-1">Adsgram Block ID</label>
            <input type="text" class="form-control premium-input border-warning" id="admin-block-id" placeholder="e.g. 28773"/>
          </div>
          <div class="mb-3">
            <label class="text-muted small mb-1">Withdraw Methods (Method:MinLimit)</label>
            <input type="text" class="form-control premium-input" id="admin-withdraw-methods" placeholder="bKash:200, Nagad:150" />
            <small class="text-muted mt-1">Example: bKash:200, Nagad:200, Binance:5</small>
          </div>
          <div class="mb-3">
            <label class="text-muted small mb-1">Daily Ad Limit</label>
            <input type="number" class="form-control premium-input" id="admin-ad-limit" />
          </div>
          <div class="mb-4">
            <label class="text-muted small mb-1">Telegram Bot Token (For alerts)</label>
            <input type="text" class="form-control premium-input" id="admin-bot-token" />
          </div>
          <button type="button" class="btn btn-danger w-100 fw-bold rounded-3 py-3" id="saveAdminSettingsBtn">Save All Configurations</button>
        </form>
      </div>

      <div class="premium-card p-0 mb-4 overflow-hidden">
        <div class="p-3 border-bottom border-secondary border-opacity-25 bg-dark bg-opacity-50">
            <h6 class="text-white mb-0"><i class="bi bi-people me-2 text-success"></i> Users Management</h6>
        </div>
        <div class="table-responsive">
          <table class="table table-borderless table-hover align-middle mb-0 text-white">
            <thead class="bg-dark bg-opacity-50 text-muted small">
              <tr>
                <th class="py-3 px-3">User</th>
                <th class="py-3 text-center">Ads</th>
                <th class="py-3 text-end px-3">Balance / Edit</th>
              </tr>
            </thead>
            <tbody id="admin-user-list"></tbody>
          </table>
        </div>
      </div>

      <!-- Withdraw Requests Management -->
      <div class="premium-card p-0 mb-4 overflow-hidden">
        <div class="p-3 border-bottom border-secondary border-opacity-25 bg-dark bg-opacity-50">
            <h6 class="text-white mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i> Withdraw Requests</h6>
        </div>
        <div class="table-responsive">
          <table class="table table-borderless table-hover align-middle mb-0 text-white">
            <thead class="bg-dark bg-opacity-50 text-muted small">
              <tr>
                <th class="py-3 px-3">User Details</th>
                <th class="py-3 text-center">Amount & Info</th>
                <th class="py-3 text-end px-3">Action</th>
              </tr>
            </thead>
            <tbody id="admin-withdraw-list"></tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <footer class="app-bottom-nav shadow-lg">
    <div onclick="showSection('profile')" class="nav-item" data-section="profile"><i class="bi bi-person-badge"></i><span>Profile</span></div>
    <div onclick="showSection('home')" class="nav-item active" data-section="home"><i class="bi bi-house-door"></i><span>Home</span></div>
    <div onclick="showSection('withdraw')" class="nav-item" data-section="withdraw"><i class="bi bi-wallet2"></i><span>Withdraw</span></div>
    <div onclick="showSection('admin-panel')" class="nav-item d-none" id="admin-nav-item" data-section="admin-panel"><i class="bi bi-shield-check"></i><span>Admin</span></div>
  </footer>

  <div id="toast-area" class="toast-area"></div>

  <script src="https://telegram.org/js/telegram-web-app.js"></script>

  <script>
    const ADMIN_CHAT_ID = "<?php echo $admin_chat_id; ?>";
    
    let currentUser = { id: "guest", firstName: "Loading", balance: 0, adsWatched: 0, lifetimeEarned: 0, withdrawHistory:[] };
    let appSettings = {};
    let allUsersData = {};
    let dailyBonusClaimed = false;
    let AdController = null;

    function toggleLoading(show) { 
        const loader = document.getElementById("loading-spinner");
        if(show) {
            loader.classList.remove("d-none"); loader.classList.add("d-flex");
        } else {
            loader.classList.remove("d-flex"); loader.classList.add("d-none");
        }
    }
    
    function showToast(msg, type = "success") {
      const area = document.getElementById("toast-area");
      const icon = type === 'success' ? 'bi-check-circle-fill' : type === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-info-circle-fill';
      const t = document.createElement("div"); t.className = `toast-pop ${type}`;
      t.innerHTML = `<i class="bi ${icon} fs-3"></i> <div><strong class="d-block text-capitalize text-white mb-1">${type}</strong><span class="small text-light">${msg}</span></div>`;
      area.appendChild(t); 
      setTimeout(() => { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; }, 10);
      setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
    }

    function formatCur(amount) {
        let cur = appSettings.currency || 'BDT';
        return parseFloat(amount || 0).toFixed(2) + ' ' + cur;
    }

    async function syncBackend(tgUser) {
        toggleLoading(true);
        try {
            const res = await fetch('?action=sync_user', { 
                method: 'POST', 
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(tgUser) 
            });
            const data = await res.json();
            
            currentUser = data.user;
            appSettings = data.settings;
            dailyBonusClaimed = data.bonusClaimed;
            if (data.all_users) allUsersData = data.all_users;
            
            if(window.Adsgram && appSettings.adsgramBlockId) {
                AdController = window.Adsgram.init({ blockId: appSettings.adsgramBlockId.toString().trim() });
            }
            
            updateBasicUI();
            
            if (currentUser.id === ADMIN_CHAT_ID) {
                document.getElementById("admin-nav-item").classList.remove("d-none");
                renderAdminPanel();
            }

            populateWithdrawMethods();
            renderHistory();
            updateTaskUI();
        } catch (err) { 
            console.error(err); 
            showToast("Failed to load user data.", "danger"); 
        } finally {
            toggleLoading(false);
        }
    }

    function updateBasicUI() {
        if(!currentUser) return;
        let fullName = `${currentUser.firstName} ${currentUser.lastName || ''}`.trim();
        document.querySelectorAll(".UserName").forEach(el => el.innerText = fullName);
        document.getElementById("profileUserUsername").innerText = currentUser.username ? `@${currentUser.username}` : "ID: " + currentUser.id;
        
        const elPic1 = document.getElementById("headerProfilePic");
        const elPic2 = document.getElementById("profileLargeAvatar");
        if(currentUser.photoUrl) {
            elPic1.innerHTML = `<img src="${currentUser.photoUrl}">`;
            elPic2.innerHTML = `<img src="${currentUser.photoUrl}">`;
        } else {
            elPic1.innerHTML = fullName.charAt(0).toUpperCase();
            elPic2.innerHTML = fullName.charAt(0).toUpperCase();
        }

        const balStr = formatCur(currentUser.balance);
        document.querySelectorAll(".user-balance").forEach(el => el.innerText = balStr);
        document.getElementById("lifetimeEarning").innerText = formatCur(currentUser.lifetimeEarned);
        document.getElementById("adsWatchedCount").innerText = currentUser.adsWatched;
        if(appSettings && appSettings.dailyBonusAmount) { 
            document.getElementById("daily-bonus-text").innerText = `Get ${formatCur(appSettings.dailyBonusAmount)} free`; 
        }
    }

    function updateTaskUI() {
      if(!appSettings) return;
      let total = parseInt(appSettings.dailyAdLimit) || 10;
      let completed = currentUser.adsWatched % (total + 1);
      if(completed > total) completed = total;
      let left = total - completed;
      let percent = Math.round((completed / total) * 100);
      
      document.querySelectorAll(".taskCount").forEach(e=>e.innerText=total);
      document.querySelectorAll(".tasksCompleted").forEach(e=>e.innerText=completed);
      document.querySelectorAll(".tasksRemaining").forEach(e=>e.innerText=left);
      
      document.getElementById("progressBarFill").style.width = `${percent}%`;
      document.getElementById("progress-percent").innerText = `${percent}%`;
      
      const btn = document.getElementById("show-ad");
      if (left <= 0) { 
          btn.disabled = true; btn.innerHTML = '<i class="bi bi-clock-fill me-2"></i> Limit Reached for Today'; 
      } else { 
          btn.disabled = false; btn.innerHTML = '<i class="bi bi-play-circle-fill me-2 fs-5 align-middle"></i> Start Earning Task'; 
      }
    }

    function populateWithdrawMethods() {
      const s = document.getElementById("payment-method");
      s.innerHTML = '<option value="" data-min="0">Choose Method</option>';
      if(appSettings && appSettings.withdrawMethods) {
          let methodsStr = Array.isArray(appSettings.withdrawMethods) ? appSettings.withdrawMethods.join(", ") : appSettings.withdrawMethods;
          const methods = methodsStr.split(',');
          methods.forEach(m => {
              let parts = m.split(':');
              let name = parts[0] ? parts[0].trim() : '';
              let min = parts[1] ? parseFloat(parts[1].trim()) : 0;
              if(name) { s.innerHTML += `<option value="${name}" data-min="${min}">${name}</option>`; }
          });
      }
      updateMinLimit();
    }

    window.updateMinLimit = function() {
        const sel = document.getElementById("payment-method");
        if(sel.selectedIndex > 0) {
            const min = sel.options[sel.selectedIndex].getAttribute("data-min");
            document.getElementById("min-limit-text").innerText = `⚠️ Minimum required: ${formatCur(min)}`;
            document.getElementById("withdraw-amount").min = min;
        } else {
            document.getElementById("min-limit-text").innerText = '';
        }
    }

    function renderHistory() {
        const list = document.getElementById("withdraw-history-list");
        list.innerHTML = '';
        const history = currentUser.withdrawHistory ||[];
        if(history.length === 0) { list.innerHTML = '<div class="text-muted small text-center py-3">No withdraw history found.</div>'; return; }
        [...history].reverse().forEach(h => {
            let badgeClass = 'badge-pending';
            if(h.status === 'Completed') badgeClass = 'badge-completed';
            else if(h.status === 'Cancelled') badgeClass = 'badge-cancelled';

            list.innerHTML += `
            <div class="history-item">
                <div>
                    <div class="text-white fw-medium mb-1">${h.method} <span class="text-muted small ms-1">(${h.address})</span></div>
                    <div class="text-muted small" style="font-size:0.7rem"><i class="bi bi-calendar2-check me-1"></i>${h.date}</div>
                </div>
                <div class="text-end">
                    <div class="text-info fw-bold mb-1">${formatCur(h.amount)}</div>
                    <span class="${badgeClass}">${h.status}</span>
                </div>
            </div>`;
        });
    }

    function renderAdminPanel() {
       document.getElementById("admin-currency").value = appSettings.currency || 'BDT';
       document.getElementById("admin-daily-bonus").value = appSettings.dailyBonusAmount || 0;
       document.getElementById("admin-ad-reward").value = appSettings.adRewardAmount || 0;
       document.getElementById("admin-block-id").value = appSettings.adsgramBlockId || '';
       
       let methodsStr = Array.isArray(appSettings.withdrawMethods) ? appSettings.withdrawMethods.join(", ") : (appSettings.withdrawMethods || '');
       document.getElementById("admin-withdraw-methods").value = methodsStr;
       document.getElementById("admin-ad-limit").value = appSettings.dailyAdLimit || 10;
       document.getElementById("admin-bot-token").value = appSettings.botToken || '';
       
       const tb = document.getElementById("admin-user-list");
       tb.innerHTML = '';
       let allRequests =[]; // উইথড্র লিস্টের জন্য

       Object.values(allUsersData).forEach(u => {
          // ইউজার লিস্ট টেবিল
          tb.innerHTML += `
          <tr class="border-bottom border-secondary border-opacity-10">
            <td class="px-3 py-3">
                <div class="text-white fw-medium">${u.firstName}</div>
                <div class="text-muted" style="font-size:0.7rem">ID: ${u.id}</div>
            </td>
            <td class="text-center align-middle text-info">${u.adsWatched}</td>
            <td class="text-end align-middle px-3">
                <span class="text-success fw-bold d-block mb-1">${formatCur(u.balance)}</span>
                <button class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:0.7rem;" onclick="openEditModal('${u.id}', ${u.balance})"><i class="bi bi-pencil"></i> Edit</button>
            </td>
          </tr>`;

          // উইথড্র ডেটা কালেক্ট
          if(u.withdrawHistory && u.withdrawHistory.length > 0) {
              u.withdrawHistory.forEach((req, idx) => {
                  allRequests.push({ uid: u.id, name: u.firstName, index: idx, ...req });
              });
          }
       });

       // উইথড্র লিস্ট টেবিল রেন্ডার
       const wdList = document.getElementById("admin-withdraw-list");
       wdList.innerHTML = '';
       allRequests.reverse().forEach(req => {
            let statusBadge = '';
            if (req.status === 'Pending') statusBadge = '<span class="badge-pending">Pending</span>';
            else if (req.status === 'Completed') statusBadge = '<span class="badge-completed">Completed</span>';
            else if (req.status === 'Cancelled') statusBadge = '<span class="badge-cancelled">Cancelled</span>';

            let actionButtons = '';
            if (req.status === 'Pending') {
                actionButtons = `
                <div class="d-flex justify-content-end gap-1 mt-1">
                    <button class="btn btn-sm btn-success py-0 px-2" style="font-size:0.7rem;" onclick="changeWithdrawStatus('${req.uid}', ${req.index}, 'Completed')"><i class="bi bi-check2"></i> Done</button>
                    <button class="btn btn-sm btn-danger py-0 px-2" style="font-size:0.7rem;" onclick="changeWithdrawStatus('${req.uid}', ${req.index}, 'Cancelled')"><i class="bi bi-x"></i> Cancel</button>
                </div>`;
            } else {
                 actionButtons = `<small class="text-muted" style="font-size:0.75rem;">Processed</small>`;
            }

            wdList.innerHTML += `
            <tr class="border-bottom border-secondary border-opacity-10">
                <td class="px-3 py-3">
                    <div class="text-white fw-medium" style="font-size:0.9rem;">${req.name}</div>
                    <div class="text-muted" style="font-size:0.7rem">ID: ${req.uid}</div>
                    <div class="text-muted mt-1" style="font-size:0.7rem"><i class="bi bi-calendar2"></i> ${req.date}</div>
                </td>
                <td class="text-center align-middle">
                    <div class="text-info fw-bold mb-1">${formatCur(req.amount)}</div>
                    <div class="text-white small">${req.method}</div>
                    <div class="text-muted" style="font-size:0.75rem">${req.address}</div>
                    <div class="mt-1">${statusBadge}</div>
                </td>
                <td class="text-end align-middle px-3">
                    ${actionButtons}
                </td>
            </tr>`;
       });
    }

    // 🚀 NEW: Withdraw Status Update Function
    window.changeWithdrawStatus = async function(uid, index, newStatus) {
        if (!confirm(`Are you sure you want to mark this request as ${newStatus}?`)) return;
        toggleLoading(true);
        const r = await fetch('?action=update_withdraw_status', { 
            method: 'POST', 
            headers: {'Content-Type': 'application/json'}, 
            body: JSON.stringify({admin_id: currentUser.id, target_user: uid, index: index, status: newStatus}) 
        });
        const d = await r.json();
        if (d.success) { 
            showToast(`Status updated to ${newStatus}!`); 
            syncBackend({id: currentUser.id}); 
        } else {
            showToast("Failed to update status", "danger");
        }
        toggleLoading(false);
    }

    window.openEditModal = function(uid, bal) {
        document.getElementById('editUid').value = uid;
        document.getElementById('editBalInput').value = bal;
        document.getElementById('editModalOverlay').style.display = 'flex';
    }
    window.closeEditModal = function() { document.getElementById('editModalOverlay').style.display = 'none'; }
    window.saveEditedBalance = async function() {
        const uid = document.getElementById('editUid').value;
        const bal = parseFloat(document.getElementById('editBalInput').value);
        toggleLoading(true);
        const r = await fetch('?action=edit_balance', { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({admin_id: currentUser.id, target_user: uid, new_balance: bal}) });
        const d = await r.json();
        if(d.success) { showToast("Balance Updated!"); closeEditModal(); syncBackend({id: currentUser.id}); }
        toggleLoading(false);
    }

    document.addEventListener("DOMContentLoaded", () => {
      let tgUser = { id: "guest_" + Math.floor(Math.random()*100), firstName: "Demo User" };
      if (window.Telegram && window.Telegram.WebApp && Telegram.WebApp.initDataUnsafe.user) {
         Telegram.WebApp.expand();
         tgUser = Telegram.WebApp.initDataUnsafe.user;
      }
      syncBackend(tgUser);

      document.getElementById("show-ad").addEventListener("click", () => {
        if (!AdController) return showToast("Ads not configured or AdBlock active", "danger");
        toggleLoading(true);
        AdController.show().then(async () => {
           const r = await fetch('?action=add_reward', { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: currentUser.id, type: 'ad'}) });
           const d = await r.json();
           if(d.success) { currentUser = d.user; updateBasicUI(); updateTaskUI(); showToast(`Earned ${formatCur(appSettings.adRewardAmount)}!`, "success"); }
           toggleLoading(false);
        }).catch(() => { toggleLoading(false); showToast("Ad closed early or failed", "warning"); });
      });

      document.getElementById("claim-daily-bonus").addEventListener("click", () => {
        if(dailyBonusClaimed) return showToast("You already claimed today!", "warning");
        if (!AdController) return showToast("Ad system not ready", "danger");
        toggleLoading(true);
        AdController.show().then(async () => {
           const r = await fetch('?action=add_reward', { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: currentUser.id, type: 'bonus'}) });
           const d = await r.json();
           if(d.success) { currentUser = d.user; dailyBonusClaimed = true; updateBasicUI(); showToast("Bonus Claimed!"); }
           else showToast(d.message, "warning");
           toggleLoading(false);
        }).catch(() => { toggleLoading(false); showToast("Watch full ad to get bonus", "warning"); });
      });

      document.getElementById("submitWithdrawBtn").addEventListener("click", async () => {
        const amt = parseFloat(document.getElementById("withdraw-amount").value);
        const sel = document.getElementById("payment-method");
        const method = sel.value;
        const addr = document.getElementById("withdraw-address").value;
        
        if (!amt || !method || !addr) return showToast("Please fill all required fields", "warning");
        
        const minLimit = parseFloat(sel.options[sel.selectedIndex].getAttribute("data-min"));
        if (amt < minLimit) return showToast(`Minimum limit for ${method} is ${formatCur(minLimit)}`, "danger");
        if (amt > currentUser.balance) return showToast("Insufficient balance!", "danger");

        toggleLoading(true);
        const r = await fetch('?action=withdraw', { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: currentUser.id, amount: amt, method: method, address: addr}) });
        const d = await r.json();
        
        if(d.success) {
            currentUser = d.user; 
            updateBasicUI(); 
            renderHistory();
            document.getElementById("withdraw-form").reset();
            updateMinLimit();
            showToast("Withdraw Request Sent!");
            syncBackend({id: currentUser.id}); // Auto Refresh
        } else { 
            showToast(d.message, "danger"); 
        }
        toggleLoading(false);
      });

      document.getElementById("saveAdminSettingsBtn").addEventListener("click", async () => {
         const newSet = {
             currency: document.getElementById("admin-currency").value.trim() || 'BDT',
             dailyBonusAmount: parseFloat(document.getElementById("admin-daily-bonus").value),
             adRewardAmount: parseFloat(document.getElementById("admin-ad-reward").value),
             adsgramBlockId: document.getElementById("admin-block-id").value,
             withdrawMethods: document.getElementById("admin-withdraw-methods").value,
             dailyAdLimit: parseInt(document.getElementById("admin-ad-limit").value),
             botToken: document.getElementById("admin-bot-token").value.trim()
         };
         toggleLoading(true);
         const r = await fetch('?action=update_settings', { method:'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({admin_id: currentUser.id, settings: newSet}) });
         const d = await r.json();
         if(d.success) { 
             appSettings = newSet; 
             populateWithdrawMethods(); 
             updateBasicUI(); updateTaskUI();
             renderAdminPanel();
             
             if(window.Adsgram && appSettings.adsgramBlockId) {
                 AdController = window.Adsgram.init({ blockId: appSettings.adsgramBlockId.toString().trim() });
             }
             showToast("All configurations saved successfully!"); 
         }
         toggleLoading(false);
      });
    });

    window.showSection = function(id) {
      document.querySelectorAll("main > section").forEach(s => s.classList.add("d-none"));
      document.getElementById(id).classList.remove("d-none");
      document.querySelectorAll('.app-bottom-nav .nav-item').forEach(n => n.classList.remove('active'));
      const activeNav = Array.from(document.querySelectorAll('.app-bottom-nav .nav-item')).find(nav => nav.getAttribute('data-section') === id);
      if (activeNav) activeNav.classList.add('active');
    }
  </script>
</body>
</html>