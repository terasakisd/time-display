/**
 * 日時表示（Time Display）
 * 閲覧者の端末時計を元に日本時間（Asia/Tokyo）で計算して自動更新する。
 * 設定は各要素の data-ftd-config 属性（JSON）で渡されるため、
 * 1ページに設定の異なる複数のパーツを置ける。
 */
(function () {
	'use strict';

	var WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

	var DEFAULT_CONFIG = {
		timezone: 'Asia/Tokyo',
		prefix: '最短',
		lead_minutes: 60,
		round_minutes: 30,
		show_seconds: false,
		rules: [
			{ from: '00:00', to: '08:59', type: 'fixed', day_offset: 0, time: '10:00' },
			{ from: '09:00', to: '19:59', type: 'relative' },
			{ from: '20:00', to: '23:59', type: 'fixed', day_offset: 1, time: '10:00' }
		]
	};

	function getConfig(el) {
		var json = el.getAttribute('data-ftd-config');
		if (json) {
			try {
				return JSON.parse(json);
			} catch (e) { /* 属性が壊れていたらデフォルトにフォールバック */ }
		}
		return window.FTD_CONFIG || DEFAULT_CONFIG;
	}

	function hmsToSeconds(hms) {
		var p = String(hms).split(':');
		return (parseInt(p[0], 10) || 0) * 3600 + (parseInt(p[1], 10) || 0) * 60 + (parseInt(p[2], 10) || 0);
	}

	function leadSeconds(config) {
		return config.lead_seconds != null && config.lead_seconds !== '' ? config.lead_seconds : config.lead_minutes * 60;
	}

	function roundSeconds(config) {
		var r = config.round_seconds != null && config.round_seconds !== '' ? config.round_seconds : config.round_minutes * 60;
		return Math.max(1, r);
	}

	// 現在時刻を設定タイムゾーンの各要素に分解し、「そのローカル時刻をUTCとみなした」Dateを返す
	// （こうすると getUTC* 系で日付計算・曜日取得が閲覧者のタイムゾーンに依存せずできる）
	function nowAsLocal(config) {
		var parts = new Intl.DateTimeFormat('en-US', {
			timeZone: config.timezone || 'Asia/Tokyo',
			year: 'numeric',
			month: 'numeric',
			day: 'numeric',
			hour: 'numeric',
			minute: 'numeric',
			second: 'numeric',
			hour12: false
		}).formatToParts(new Date());

		var v = {};
		parts.forEach(function (p) {
			v[p.type] = parseInt(p.value, 10);
		});
		// hour12:false でも環境により 24 が返ることがあるため補正
		if (v.hour === 24) {
			v.hour = 0;
		}
		return new Date(Date.UTC(v.year, v.month - 1, v.day, v.hour, v.minute, v.second));
	}

	function calcTarget(now, config) {
		var nowSec = now.getUTCHours() * 3600 + now.getUTCMinutes() * 60 + now.getUTCSeconds();
		var i, rule, t, p, mod, round, to;

		for (i = 0; i < config.rules.length; i++) {
			rule = config.rules[i];
			to = hmsToSeconds(rule.to);
			// "08:59" のような分単位の終了時刻は「08:59:59まで」の意図なので59秒を補う
			if (String(rule.to).split(':').length < 3) {
				to += 59;
			}
			if (nowSec < hmsToSeconds(rule.from) || nowSec > to) {
				continue;
			}

			if (rule.type === 'fixed') {
				t = new Date(now.getTime() + (rule.day_offset || 0) * 24 * 60 * 60 * 1000);
				p = String(rule.time).split(':');
				t.setUTCHours(parseInt(p[0], 10) || 0, parseInt(p[1], 10) || 0, parseInt(p[2], 10) || 0, 0);
				return t;
			}

			// relative: +加算時間 → 切り上げ単位で切り上げ（ちょうど割り切れる時刻ならそのまま）
			t = new Date(now.getTime() + leadSeconds(config) * 1000);
			round = roundSeconds(config);
			mod = (t.getUTCHours() * 3600 + t.getUTCMinutes() * 60 + t.getUTCSeconds()) % round;
			if (mod !== 0) {
				t = new Date(t.getTime() + (round - mod) * 1000);
			}
			t.setUTCMilliseconds(0);
			return t;
		}

		// どのルールにも当てはまらない場合は現在時刻をそのまま返す（設定不備の保険）
		return now;
	}

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function format(t, config) {
		var time = t.getUTCHours() + ':' + pad(t.getUTCMinutes());
		if (config.show_seconds) {
			time += ':' + pad(t.getUTCSeconds());
		}
		return (
			(config.prefix != null ? config.prefix : '最短') +
			(t.getUTCMonth() + 1) + '月' +
			t.getUTCDate() + '日' +
			'(' + WEEKDAYS[t.getUTCDay()] + ')' +
			time
		);
	}

	// 残り秒数 → 「あと1日2時間30分15秒」形式（0日のときは「日」を省略）
	function formatTimer(diff, config) {
		diff = Math.max(0, diff);
		var d = Math.floor(diff / 86400);
		var h = Math.floor((diff % 86400) / 3600);
		var m = Math.floor((diff % 3600) / 60);
		var s = diff % 60;
		var text = config.prefix != null ? config.prefix : '';
		if (d > 0) {
			text += d + '日';
		}
		return text + h + '時間' + pad(m) + '分' + pad(s) + '秒';
	}

	function render() {
		var els = document.querySelectorAll('[data-time-display]');
		els.forEach(function (el) {
			var config = getConfig(el);
			var now = nowAsLocal(config);
			var target = calcTarget(now, config);

			// タイマー形式: 表示時刻までの残り時間をカウントダウン
			if (config.display_mode === 'timer') {
				var diff = Math.floor((target.getTime() - now.getTime()) / 1000);
				if (diff <= 0 && config.expired_text) {
					el.textContent = config.expired_text;
				} else {
					el.textContent = formatTimer(diff, config);
				}
				return;
			}

			// 計算結果が現在より前（決まった時刻を過ぎている等）なら、設定されていれば代替文言を表示
			if (config.expired_text && target.getTime() <= now.getTime()) {
				el.textContent = config.expired_text;
			} else {
				el.textContent = format(target, config);
			}
		});
	}

	function start() {
		render();
		// タイマー形式・秒表示のパーツが1つでもあれば毎秒、なければ毎分更新
		var everySecond = false;
		document.querySelectorAll('[data-time-display]').forEach(function (el) {
			var config = getConfig(el);
			if (config.show_seconds || config.display_mode === 'timer') {
				everySecond = true;
			}
		});
		setInterval(render, everySecond ? 1000 : 60 * 1000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
