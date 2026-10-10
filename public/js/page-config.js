(function () {
	var cachedConfig = null;

	function getPageConfig() {
		if (!cachedConfig) {
			try {
				var el = document.getElementById("ovf-page-config");
				cachedConfig =
					el && el.textContent ? JSON.parse(el.textContent) : {};
			} catch (e) {
				console.error("Failed to parse ovf-page-config", e);
				cachedConfig = {};
			}
		}
		return cachedConfig;
	}

	function t(key, params, fallback) {
		var cfg = getPageConfig();
		var i18n = cfg.i18n || {};
		var val =
			i18n[key] !== undefined && i18n[key] !== null
				? i18n[key]
				: fallback !== undefined
					? fallback
					: key;

		if (typeof val === "string" && params && typeof params === "object") {
			for (var placeholder in params) {
				if (Object.prototype.hasOwnProperty.call(params, placeholder)) {
					val = val.split("{" + placeholder + "}").join(params[placeholder]);
				}
			}
		}
		return val;
	}

	window.getPageConfig = getPageConfig;
	window.t = t;
	window.OVF = window.OVF || {};
	window.OVF.getPageConfig = getPageConfig;
	window.OVF.t = t;
})();
