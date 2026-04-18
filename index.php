<?php
// 设置API路径
define('API_URI', api_uri());
// 设置中文歌词
define('TLYRIC', true);
// 设置歌单文件缓存及时间
define('CACHE', false);
define('CACHE_TIME', 86400);
// 设置短期缓存-需要安装apcu
define('APCU_CACHE', false);
// 设置AUTH密钥-更改'meting-secret'
define('AUTH', false);
define('AUTH_SECRET', 'meting-secret');

if (!isset($_GET['type']) || !isset($_GET['id'])) {
    include __DIR__ . '/public/index.php';
    exit;
}

$server = isset($_GET['server']) ? $_GET['server'] : 'netease';
$type = $_GET['type'];
$id = $_GET['id'];

if (AUTH) {
    $auth = isset($_GET['auth']) ? $_GET['auth'] : '';
    if (in_array($type, ['url', 'pic', 'lrc'])) {
        if ($auth == '' || $auth != auth($server . $type . $id)) {
            http_response_code(403);
            exit;
        }
    }
}

// 数据格式
if (in_array($type, ['song', 'playlist'])) {
    header('content-type: application/json; charset=utf-8;');
} else if (in_array($type, ['name', 'lrc', 'artist'])) {
    header('content-type: text/plain; charset=utf-8;');
}

// 允许跨站
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// include __DIR__ . '/vendor/autoload.php';
// you can use 'Meting.php' instead of 'autoload.php'
include __DIR__ . '/src/Meting.php';

use Metowolf\Meting;

$api = new Meting($server);
$api->format(true);

// 设置cookie
if ($server == 'netease') {
    $api->cookie('osver=%E7%89%88%E6%9C%AC%2010.13.3%EF%BC%88%E7%89%88%E5%8F%B7%2017D47%EF%BC%89; os=osx; appver=1.5.9; MUSIC_U=00847C63893E8CD6AB4272F664E975CC521EF49F3E4771DD2D7493A219F0DE9CAA9CAB469051AA2570BA0300643F4538EDB0A3F6EBB276DE070E43D66C5454DBB67B16748E9FA57296FF639EE779A172D7F9F24C00959B0B77ADE72202C92F3F4F1D932F441CAF915D2F0BCE040FA8FB8EB1F12590B96A91E2894E126E8706A9D0BDCA9C114CF26A0C6913B0C6A6D4FF8FFA6D65654C91DFC432F9FF47C2910DB34C7C8683D8144F98A7BAE8B2C1AA0B6C96BF8414B90320F8A519C3FFED976F52651B37F6EB7F709EACC456BA869DFC67E33EFE14F79A0DCE5861188DFE633F5DC217454D16C5C3F58239B71A275DF0102B02D0C0B2D89186F4F686A60C18664F7D0BA42F50029B488B7A76966779994F80465A8F69A57A5D074119B7B3491122066C0D9106D5C8145B1173AFA109A397D7A35A5C7915731CFCB86B9833A4B9F2E16401AF13EC07893B22FC47DCE98B156EE999FBEFF902821076048651C485DE51D943FC1BE31C729AD5BA31D4BD3467CEABE2BCC59DFF3AF223EF955DFECFCDA0BCD150CEF3E69D9FD0CB32FD07D74F; channel=netease;');
}

if ($type == 'playlist') {

    if (CACHE) {
        $file_path = __DIR__ . '/cache/playlist/' . $server . '_' . $id . '.json';
        if (file_exists($file_path)) {
            if ($_SERVER['REQUEST_TIME'] - filemtime($file_path) < CACHE_TIME) {
                echo file_get_contents($file_path);
                exit;
            }
        }
    }

    $data = $api->playlist($id);
    if ($data == '[]') {
        echo '{"error":"unknown playlist id"}';
        exit;
    }
    $data = json_decode($data);
    $playlist = array();
    foreach ($data as $song) {
        $playlist[] = array(
            'name'   => $song->name,
            'artist' => implode('/', $song->artist),
            'url'    => API_URI . '?server=' . $song->source . '&type=url&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'url' . $song->url_id) : ''),
            'pic'    => API_URI . '?server=' . $song->source . '&type=pic&id=' . $song->pic_id . (AUTH ? '&auth=' . auth($song->source . 'pic' . $song->pic_id) : ''),
            'lrc'    => API_URI . '?server=' . $song->source . '&type=lrc&id=' . $song->lyric_id . (AUTH ? '&auth=' . auth($song->source . 'lrc' . $song->lyric_id) : '')
        );
    }
    $playlist = json_encode($playlist);

    if (CACHE) {
        // ! mkdir /cache/playlist
        file_put_contents($file_path, $playlist);
    }

    echo $playlist;
} else {
    $need_song = !in_array($type, ['url', 'pic', 'lrc']);
    if ($need_song && !in_array($type, ['name', 'artist', 'song'])) {
        echo '{"error":"unknown type"}';
        exit;
    }

    if (APCU_CACHE) {
        $apcu_time = $type == 'url' ? 600 : 36000;
        $apcu_type_key = $server . $type . $id;
        if (apcu_exists($apcu_type_key)) {
            $data = apcu_fetch($apcu_type_key);
            return_data($type, $data);
        }
        if ($need_song) {
            $apcu_song_id_key = $server . 'song_id' . $id;
            if (apcu_exists($apcu_song_id_key)) {
                $song = apcu_fetch($apcu_song_id_key);
            }
        }
    }

    if (!$need_song) {
        $data = song2data($api, null, $type, $id);
    } else {
        if (!isset($song)) $song = $api->song($id);
        if ($song == '[]') {
            echo '{"error":"unknown song"}';
            exit;
        }
        if (APCU_CACHE) {
            apcu_store($apcu_song_id_key, $song, $apcu_time);
        }
        $data = song2data($api, json_decode($song)[0], $type, $id);
    }

    if (APCU_CACHE) {
        apcu_store($apcu_type_key, $data, $apcu_time);
    }

    return_data($type, $data);
}

function api_uri() // static
{
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
}

function auth($name)
{
    return hash_hmac('sha1', $name, AUTH_SECRET);
}

function song2data($api, $song, $type, $id)
{
    $data = '';
    switch ($type) {
        case 'name':
            $data = $song->name;
            break;

        case 'artist':
            $data = implode('/', $song->artist);
            break;

        case 'url':
            $m_url = json_decode($api->url($id, 320))->url;
            if ($m_url == '') break;
            // url format
            if ($api->server == 'netease') {
                if ($m_url[4] != 's') $m_url = str_replace('http', 'https', $m_url);
            }

            $data = $m_url;
            break;

        case 'pic':
            $data = json_decode($api->pic($id, 90))->url;
            break;

        case 'lrc':
            $lrc_data = json_decode($api->lyric($id));
            if ($lrc_data->lyric == '') {
                $lrc = '[00:00.00]这似乎是一首纯音乐呢，请尽情欣赏它吧！';
            } else if ($lrc_data->tlyric == '') {
                $lrc = $lrc_data->lyric;
            } else if (TLYRIC) { // lyric_cn
                $lrc_arr = explode("\n", $lrc_data->lyric);
                $lrc_cn_arr = explode("\n", $lrc_data->tlyric);
                $lrc_cn_map = array();
                foreach ($lrc_cn_arr as $i => $v) {
                    if ($v == '') continue;
                    $line = explode(']', $v, 2);
                    // 格式化处理
                    $line[1] = trim(preg_replace('/\s\s+/', ' ', $line[1]));
                    $lrc_cn_map[$line[0]] = $line[1];
                    unset($lrc_cn_arr[$i]);
                }
                foreach ($lrc_arr as $i => $v) {
                    if ($v == '') continue;
                    $key = explode(']', $v, 2)[0];
                    if (!empty($lrc_cn_map[$key]) && $lrc_cn_map[$key] != '//') {
                        $lrc_arr[$i] .= ' (' . $lrc_cn_map[$key] . ')';
                        unset($lrc_cn_map[$key]);
                    }
                }
                $lrc = implode("\n", $lrc_arr);
            } else {
                $lrc = $lrc_data->lyric;
            }
            $data = $lrc;
            break;

        case 'song':
            $data = json_encode(array(array(
                'name'   => $song->name,
                'artist' => implode('/', $song->artist),
                'url'    => API_URI . '?server=' . $song->source . '&type=url&id=' . $song->url_id . (AUTH ? '&auth=' . auth($song->source . 'url' . $song->url_id) : ''),
                'pic'    => API_URI . '?server=' . $song->source . '&type=pic&id=' . $song->pic_id . (AUTH ? '&auth=' . auth($song->source . 'pic' . $song->pic_id) : ''),
                'lrc'    => API_URI . '?server=' . $song->source . '&type=lrc&id=' . $song->lyric_id . (AUTH ? '&auth=' . auth($song->source . 'lrc' . $song->lyric_id) : '')
            )));
            break;
    }
    if ($data == '') exit;
    return $data;
}

function return_data($type, $data)
{
    if (in_array($type, ['url', 'pic'])) {
        header('Location: ' . $data);
    } else {
        echo $data;
    }
    exit;
}
