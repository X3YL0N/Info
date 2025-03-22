<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Dhaka');

// Dynamic domain detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$domain = $protocol . "://" . $_SERVER['HTTP_HOST'];

function cleanUsername($input) {
    $input = str_replace("@", "", trim($input));
    if (preg_match('/t\.me\/(?:s\/)?([a-zA-Z0-9_]+)/', $input, $matches)) {
        return $matches[1];
    }
    return preg_replace('/[^a-zA-Z0-9_]/', '', $input);
}

function getHtml($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function extractText($pattern, $html) {
    preg_match($pattern, $html, $match);
    return isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES, 'UTF-8') : "Unknown";
}

function extractAllMatches($pattern, $html) {
    preg_match_all($pattern, $html, $matches);
    return $matches[1] ?? [];
}

function downloadProfilePicture($url, $username) {
    $folder = "tg-photo";
    if (!file_exists($folder)) {
        mkdir($folder, 0777, true);
    }

    $img_path = "{$folder}/{$username}.jpg";
    $ch = curl_init($url);
    $fp = fopen($img_path, 'wb');

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    return file_exists($img_path) ? $img_path : "default.jpg";
}

function calculateAge($date) {
    $datetime1 = new DateTime($date);
    $datetime2 = new DateTime();
    $interval = $datetime1->diff($datetime2);
    return $interval->y . " years, " . $interval->m . " months, " . $interval->d . " days";
}

function getBestPostTime($post_dates, $post_views) {
    if (empty($post_dates) || empty($post_views)) {
        return "Unknown";
    }

    $time_slots = [];
    foreach ($post_dates as $index => $datetime) {
        $hour = date("H", strtotime($datetime . " +6 hours"));
        if (!isset($time_slots[$hour])) {
            $time_slots[$hour] = 0;
        }
        $time_slots[$hour] += $post_views[$index] ?? 0;
    }

    if (empty($time_slots)) {
        return "Unknown";
    }

    arsort($time_slots);
    $best_hour = array_key_first($time_slots);
    return "{$best_hour}:00 - " . ($best_hour + 1) . ":00 (Bangladesh Time)";
}

function getChannelInfo($input) {
    global $domain;

    $username = cleanUsername($input);
    if (!$username) {
        return json_encode(["error" => "Invalid username format."], JSON_UNESCAPED_UNICODE);
    }

    $api_url = "https://t.me/s/{$username}";
    $html = getHtml($api_url);

    if (!$html || strpos($html, 'tgme_page') === false) {
        return json_encode(["error" => "Failed to fetch data from Telegram."], JSON_UNESCAPED_UNICODE);
    }

    // Extract Basic Info
    $title = extractText('/<meta property="og:title" content="([^"]+)"/', $html);
    $profile_picture = extractText('/<meta property="og:image" content="([^"]+)"/', $html);
    $bio = extractText('/<meta property="og:description" content="([^"]+)"/', $html);
    $category = extractText('/<div class="tgme_page_extra">([^<]+)<\/div>/', $html);

    // Profile picture download and custom URL
    $local_pfp = downloadProfilePicture($profile_picture, $username);
    $pfp_url = "{$domain}/{$local_pfp}";

    // Extract Members Count
    preg_match('/(\d+)\s+subscribers/', $html, $members_match);
    $total_members = isset($members_match[1]) ? (int)$members_match[1] : "Unknown";

    // Extract Post Dates & View Counts
    $recent_posts = extractAllMatches('/datetime="([^"]+)"/', $html);
    $view_counts = extractAllMatches('/<span class="tgme_widget_message_views">(\d+)<\/span>/', $html);
    $post_links = extractAllMatches('/<a class="tgme_widget_message_date" href="([^"]+)"/', $html);

    // Ensure the arrays have valid data
    if (empty($recent_posts)) {
        return json_encode(["error" => "No posts found for this channel."], JSON_UNESCAPED_UNICODE);
    }

    // Calculate Age
    $created_date = min($recent_posts);
    $channel_age = calculateAge($created_date);

    // Last Post Date
    $last_post_date = end($recent_posts);
    $last_post_date = date("Y-m-d H:i:s", strtotime($last_post_date . " +6 hours"));

    // Average Views per Post
    $total_views = array_sum($view_counts);
    $total_posts = count($recent_posts);
    $average_views = $total_posts > 0 ? round($total_views / $total_posts, 2) : "Unknown";

    // Most Viewed Post
    $most_viewed_post = !empty($view_counts) ? max($view_counts) : "Unknown";
    $most_viewed_post_link = "Unknown";
    
    if ($most_viewed_post !== "Unknown") {
        $most_viewed_post_index = array_search($most_viewed_post, $view_counts);
        $most_viewed_post_link = $post_links[$most_viewed_post_index] ?? "Unknown";
    }

    // Best Time to Post
    $best_post_time = getBestPostTime(array_slice($recent_posts, -10), array_slice($view_counts, -10));

    // Active Members Calculation
    $active_members = ($total_members !== "Unknown" && $average_views !== "Unknown") ? round(($average_views / $total_members) * 100, 2) . "%" : "Unknown";

    // Last 5 Posts with Views & Links
    $last_5_posts = [];
    for ($i = max(0, count($recent_posts) - 5); $i < count($recent_posts); $i++) {
        $last_5_posts[] = [
            "date" => date("Y-m-d H:i:s", strtotime($recent_posts[$i] . " +6 hours")),
            "views" => $view_counts[$i] ?? "Unknown",
            "link" => $post_links[$i] ?? "Unknown"
        ];
    }

    // JSON Response
    return json_encode([
        "channel_info" => [
            "username" => $username,
            "title" => $title,
            "profile_picture" => $pfp_url,
            "bio" => $bio,
            "category" => $category,
            "channel_age" => $channel_age,
            "channel_link" => "https://t.me/{$username}"
        ],
        "stats" => [
            "total_members" => $total_members,
            "average_views" => $average_views,
            "active_members" => $active_members,
            "most_viewed_post" => [
                "views" => $most_viewed_post,
                "link" => $most_viewed_post_link
            ],
            "total_posts" => $total_posts
        ],
        "activity" => [
            "last_post_date" => $last_post_date,
            "best_time_to_post" => $best_post_time,
            "last_5_posts" => $last_5_posts
        ],
        "dev" => "@Abdullha_404"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (isset($_GET['username'])) {
    header("Content-Type: application/json");
    echo getChannelInfo($_GET['username']);
} else {
    echo json_encode(["error" => "Username parameter is missing"], JSON_UNESCAPED_UNICODE);
}
