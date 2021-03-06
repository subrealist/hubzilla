<?php


function is_matrix_url($url) {

	// in-memory cache to avoid repeated queries for the same host
	static $remembered = [];

	$m = @parse_url($url);
	if($m['host']) {

		if(array_key_exists($m['host'],$remembered))
			return $remembered[$m['host']];

		$r = q("select hubloc_url from hubloc where hubloc_host = '%s' and hubloc_network = 'zot' limit 1",
			dbesc($m['host'])
		);
		if($r) {
			$remembered[$m['host']] = true;
			return true;
		}
		$remembered[$m['host']] = false;
	}
	return false;
}

/**
 * @brief Adds a zid parameter to a url.
 *
 * @param string $s
 *   The url to accept the zid
 * @param boolean $address
 *   $address to use instead of session environment
 * @return string
 *
 * @hooks 'zid'
 *      string url - url to accept zid
 *      string zid - urlencoded zid
 *      string result - the return string we calculated, change it if you want to return something else
 */

function zid($s,$address = '') {
	if (! strlen($s) || strpos($s,'zid='))
		return $s;

	$m = parse_url($s);
	$fragment = ((array_key_exists('fragment',$m) && $m['fragment']) ? $m['fragment'] : false);
	if($fragment !== false)
		$s = str_replace('#' . $fragment,'',$s);

	$has_params = ((strpos($s,'?')) ? true : false);
	$num_slashes = substr_count($s, '/');
	if (! $has_params)
		$has_params = ((strpos($s, '&')) ? true : false);

	$achar = strpos($s,'?') ? '&' : '?';

	$mine = get_my_url();
	$myaddr = (($address) ? $address : get_my_address());

	/**
	 * @FIXME checking against our own channel url is no longer reliable. We may have a lot
	 * of urls attached to our channel. Should probably match against our site, since we
	 * will not need to remote authenticate on our own site anyway.
	 */

	if ($mine && $myaddr && (! link_compare($mine,$s)))
		$zurl = $s . (($num_slashes >= 3) ? '' : '/') . $achar . 'zid=' . urlencode($myaddr);
	else
		$zurl = $s;

	// put fragment at the end

	if($fragment)
		$zurl .= '#' . $fragment;

	$arr = array('url' => $s, 'zid' => urlencode($myaddr), 'result' => $zurl);
	call_hooks('zid', $arr);

	return $arr['result'];
}


function strip_zids($s) {
	return preg_replace('/[\?&]zid=(.*?)(&|$)/ism','$2',$s);
}

function strip_zats($s) {
	return preg_replace('/[\?&]zat=(.*?)(&|$)/ism','$2',$s);
}


/**
 * zidify_callback() and zidify_links() work together to turn any HTML a tags with class="zrl" into zid links
 * These will typically be generated by a bbcode '[zrl]' tag. This is done inside prepare_text() rather than bbcode()
 * because the latter is used for general purpose conversions and the former is used only when preparing text for
 * immediate display.
 *
 * Issues: Currently the order of HTML parameters in the text is somewhat rigid and inflexible.
 *    We assume it looks like \<a class="zrl" href="xxxxxxxxxx"\> and will not work if zrl and href appear in a different order.
 *
 * @param array $match
 * @return string
 */
function zidify_callback($match) {
	$is_zid = ((feature_enabled(local_channel(),'sendzid')) || (strpos($match[1],'zrl')) ? true : false);
	$replace = '<a' . $match[1] . ' href="' . (($is_zid) ? zid($match[2]) : $match[2]) . '"';
	$x = str_replace($match[0],$replace,$match[0]);

	return $x;
}

function zidify_img_callback($match) {
	$is_zid = ((feature_enabled(local_channel(),'sendzid')) || (strpos($match[1],'zrl')) ? true : false);
	$replace = '<img' . $match[1] . ' src="' . (($is_zid) ? zid($match[2]) : $match[2]) . '"';

	$x = str_replace($match[0],$replace,$match[0]);

	return $x;
}


function zidify_links($s) {
	$s = preg_replace_callback('/\<a(.*?)href\=\"(.*?)\"/ism','zidify_callback',$s);
	$s = preg_replace_callback('/\<img(.*?)src\=\"(.*?)\"/ism','zidify_img_callback',$s);

	return $s;
}




function zidify_text_callback($match) {
	$is_zid = is_matrix_url($match[2]);
	$replace = '<a' . $match[1] . ' href="' . (($is_zid) ? zid($match[2]) : $match[2]) . '"';
	$x = str_replace($match[0],$replace,$match[0]);

	return $x;
}

function zidify_text_img_callback($match) {
	$is_zid = is_matrix_url($match[2]);
	$replace = '<img' . $match[1] . ' src="' . (($is_zid) ? zid($match[2]) : $match[2]) . '"';

	$x = str_replace($match[0],$replace,$match[0]);

	return $x;
}

function zidify_text($s) {

	$s = preg_replace_callback('/\<a(.*?)href\=\"(.*?)\"/ism','zidify_text_callback',$s);
	$s = preg_replace_callback('/\<img(.*?)src\=\"(.*?)\"/ism','zidify_text_img_callback',$s);

	return $s;


}


/**
 * @brief preg_match function when fixing 'naked' links in mod item.php.
 *
 * Check if we've got a hubloc for the site and use a zrl if we do, a url if we don't.
 * Remove any existing zid= param which may have been pasted by mistake - and will have
 * the author's credentials. zid's are dynamic and can't really be passed around like
 * that.
 *
 * @param array $matches
 * @return string
 */
function red_zrl_callback($matches) {

	$zrl = is_matrix_url($matches[2]);

	$t = strip_zids($matches[2]);
	if($t !== $matches[2]) {
		$zrl = true;
		$matches[2] = $t;
	}

	if($matches[1] === '#^')
		$matches[1] = '';
	if($zrl)
		return $matches[1] . '#^[zrl=' . $matches[2] . ']' . $matches[2] . '[/zrl]';

	return $matches[1] . '#^[url=' . $matches[2] . ']' . $matches[2] . '[/url]';
}

/**
 * If we've got a url or zrl tag with a naked url somewhere in the link text,
 * escape it with quotes unless the naked url is a linked photo.
 *
 * @param array $matches
 * @return string
 */

function red_escape_zrl_callback($matches) {

	// Uncertain why the url/zrl forms weren't picked up by the non-greedy regex.

	if((strpos($matches[3], 'zmg') !== false) || (strpos($matches[3], 'img') !== false) || (strpos($matches[3],'zrl') !== false) || (strpos($matches[3],'url') !== false))
		return $matches[0];

	return '[' . $matches[1] . 'rl' . $matches[2] . ']' . $matches[3] . '"' . $matches[4] . '"' . $matches[5] . '[/' . $matches[6] . 'rl]';
}

function red_escape_codeblock($m) {
	return '[$b64' . $m[2] . base64_encode($m[1]) . '[/' . $m[2] . ']';
}

function red_unescape_codeblock($m) {
	return '[' . $m[2] . base64_decode($m[1]) . '[/' . $m[2] . ']';
}


function red_zrlify_img_callback($matches) {

	$zrl = is_matrix_url($matches[2]);

	$t = strip_zids($matches[2]);
	if($t !== $matches[2]) {
		$zrl = true;
		$matches[2] = $t;
	}

	if($zrl)
		return '[zmg' . $matches[1] . ']' . $matches[2] . '[/zmg]';

	return $matches[0];
}

