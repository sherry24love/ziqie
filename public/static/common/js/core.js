/* 扩展SentCMS对象 */
(function($) {
	/**
	 * 获取SentCMS基础配置
	 * @type {object}
	 */
	var SentCMS = window.Sent;

	/* 基础对象检测 */
	SentCMS || $.error("SentCMS基础配置没有正确加载！");

	/**
	 * 解析URL
	 * @param  {string} url 被解析的URL
	 * @return {object}     解析后的数据
	 */
	SentCMS.parse_url = function(url) {
		var parse = url.match(/^(?:([a-z]+):\/\/)?([\w-]+(?:\.[\w-]+)+)?(?::(\d+))?([\w-\/]+)?(?:\?((?:\w+=[^#&=\/]*)?(?:&\w+=[^#&=\/]*)*))?(?:#([\w-]+))?$/i);
		parse || $.error("url格式不正确！");
		return {
			"scheme": parse[1],
			"host": parse[2],
			"port": parse[3],
			"path": parse[4],
			"query": parse[5],
			"fragment": parse[6]
		};
	}

	SentCMS.parse_str = function(str) {
		var value = str.split("&"),
			vars = {},
			param;
		for (val in value) {
			param = value[val].split("=");
			vars[param[0]] = param[1];
		}
		return vars;
	}

	SentCMS.parse_name = function(name, type) {
		if (type) {
			/* 下划线转驼峰 */
			name.replace(/_([a-z])/g, function($0, $1) {
				return $1.toUpperCase();
			});

			/* 首字母大写 */
			name.replace(/[a-z]/, function($0) {
				return $0.toUpperCase();
			});
		} else {
			/* 大写字母转小写 */
			name = name.replace(/[A-Z]/g, function($0) {
				return "_" + $0.toLowerCase();
			});

			/* 去掉首字符的下划线 */
			if (0 === name.indexOf("_")) {
				name = name.substr(1);
			}
		}
		return name;
	}

	//scheme://host:port/path?query#fragment
	SentCMS.U = function(url, vars, suffix) {
		var info = this.parse_url(url),
			path = [],
			param = {},
			reg;

		/* 验证info */
		info.path || $.error("url格式错误！");
		url = info.path;

		/* 组装URL */
		if (0 === url.indexOf("/")) { //路由模式
			this.MODEL[0] == 0 && $.error("该URL模式不支持使用路由!(" + url + ")");

			/* 去掉右侧分割符 */
			if ("/" == url.substr(-1)) {
				url = url.substr(0, url.length - 1)
			}
			url = ("/" == this.DEEP) ? url.substr(1) : url.substr(1).replace(/\//g, this.DEEP);
			url = "/" + url;
		} else { //非路由模式
			/* 解析URL */
			path = url.split("/");
			path = [path.pop(), path.pop(), path.pop()].reverse();
			path[1] || $.error("SentCMS.U(" + url + ")没有指定控制器");

			if (path[0]) {
				param[this.VAR[0]] = this.MODEL[1] ? path[0].toLowerCase() : path[0];
			}

			param[this.VAR[1]] = this.MODEL[1] ? this.parse_name(path[1]) : path[1];
			param[this.VAR[2]] = path[2].toLowerCase();

			url = "?" + $.param(param);
		}

		/* 解析参数 */
		if (typeof vars === "string") {
			vars = this.parse_str(vars);
		} else if (!$.isPlainObject(vars)) {
			vars = {};
		}

		/* 解析URL自带的参数 */
		info.query && $.extend(vars, this.parse_str(info.query));

		if (vars) {
			url += "&" + $.param(vars);
		}

		if (0 != this.MODEL[0]) {
			url = url.replace("?" + (path[0] ? this.VAR[0] : this.VAR[1]) + "=", "/")
				.replace("&" + this.VAR[1] + "=", this.DEEP)
				.replace("&" + this.VAR[2] + "=", this.DEEP)
				.replace(/(\w+=&)|(&?\w+=$)/g, "")
				.replace(/[&=]/g, this.DEEP);
			if ("/" == url.substr(-1)) {
				url = url.substr(0, url.length - 1)
			}

			/* 添加伪静态后缀 */
			if (false !== suffix) {
				suffix = suffix || this.MODEL[2].split("|")[0];
				if (suffix) {
					url += "." + suffix;
				}
			}
		}

		url = this.APP + url;
		return url;
	}

	/* 设置表单的值 */
	SentCMS.setValue = function(name, value) {
		var first = name.substr(0, 1),
			input, i = 0,
			val;
		if (value === "") return;
		if ("#" === first || "." === first) {
			input = $(name);
		} else {
			input = $("[name='" + name + "']");
		}

		if (input.eq(0).is(":radio")) { //单选按钮
			input.filter("[value='" + value + "']").each(function() {
				this.checked = true
			});
		} else if (input.eq(0).is(":checkbox")) { //复选框
			if (!$.isArray(value)) {
				val = new Array();
				val[0] = value;
			} else {
				val = value;
			}
			for (i = 0, len = val.length; i < len; i++) {
				input.filter("[value='" + val[i] + "']").each(function() {
					this.checked = true
				});
			}
		} else { //其他表单选项直接设置值
			input.val(value);
		}
	}

})(jQuery);

//dom加载完成后执行的js
;
$(function() {

	// jQuery placeholder, fix for IE6,7,8,9
	var JPlaceHolder = new function () {
		this.init = function () {
			if (!('placeholder' in document.createElement('input'))) {
				$(':input[placeholder]').map(function () {
					var self = $(this), txt = self.attr('placeholder');
					self.wrap($('<div></div>').css({zoom: '1', margin: 'none', border: 'none', padding: 'none', background: 'none', position: 'relative'}));
					var pos = self.position(), h = self.outerHeight(true), paddingleft = self.css('padding-left');
					var holder = $('<span></span>').text(txt).css({position: 'absolute', left: pos.left, top: pos.top, height: h, lineHeight: h + 'px', paddingLeft: paddingleft, color: '#aaa', zIndex: '999'}).appendTo(self.parent());
					self.on('focusin focusout change keyup', function () {
						self.val() ? holder.hide() : holder.show();
					});
					holder.click(function () {
						self.get(0).focus();
					});
					self.val() && holder.hide();
				});
			}
		};
		this.init();
	};

	/*! 消息组件实例 */
	$.msg = new function () {
		var self = this;
		this.shade = [0.02, '#000'];
		this.dialogIndexs = [];
		// 关闭消息框
		this.close = function (index) {
			return layer.close(index);
		};
		// 弹出警告消息框
		this.alert = function (msg, callback) {
			var index = layer.alert(msg, {end: callback, scrollbar: false});
			return this.dialogIndexs.push(index), index;
		};
		// 确认对话框
		this.confirm = function (msg, ok, no) {
			var index = layer.confirm(msg, {title: '操作确认', btn: ['确认', '取消']}, function () {
				typeof ok === 'function' && ok.call(this);
			}, function () {
				typeof no === 'function' && no.call(this);
				self.close(index);
			});
			return index;
		};
		// 显示成功类型的消息
		this.success = function (msg, time, callback) {
			var index = layer.msg(msg, {icon: 1, shade: this.shade, scrollbar: false, end: callback, time: (time || 2) * 1000, shadeClose: true});
			return this.dialogIndexs.push(index), index;
		};
		// 显示失败类型的消息
		this.error = function (msg, time, callback) {
			var index = layer.msg(msg, {icon: 2, shade: this.shade, scrollbar: false, time: (time || 3) * 1000, end: callback, shadeClose: true});
			return this.dialogIndexs.push(index), index;
		};
		// 状态消息提示
		this.tips = function (msg, time, callback) {
			var index = layer.msg(msg, {time: (time || 3) * 1000, shade: this.shade, end: callback, shadeClose: true});
			return this.dialogIndexs.push(index), index;
		};
		// 显示正在加载中的提示
		this.loading = function (msg, callback) {
			var index = msg ? layer.msg(msg, {icon: 16, scrollbar: false, shade: this.shade, time: 0, end: callback}) : layer.load(2, {time: 0, scrollbar: false, shade: this.shade, end: callback});
			return this.dialogIndexs.push(index), index;
		};
		// 自动处理显示Think返回的Json数据
		this.auto = function (data, time) {
			return (parseInt(data.code) === 1) ? self.success(data.msg, time, function () {
				!!data.url ? (window.location.href = data.url) : $.form.reload();
				for (var i in self.dialogIndexs) {
					layer.close(self.dialogIndexs[i]);
				}
				self.dialogIndexs = [];
			}) : self.error(data.msg, 3, function () {
				!!data.url && (window.location.href = data.url);
			});
		};
	};

	//全选的实现
	$(".check-all").click(function() {
		$(".ids").prop("checked", this.checked);
	});
	$(".ids").click(function() {
		var option = $(".ids");
		option.each(function(i) {
			if (!this.checked) {
				$(".check-all").prop("checked", false);
				return false;
			} else {
				$(".check-all").prop("checked", true);
			}
		});
	});

	//ajax get请求
	$('.ajax-get').click(function() {
		var target;
		var that = this;
		if ($(this).hasClass('confirm')) {
			if (!confirm('确认要执行该操作吗?')) {
				return false;
			}
		}
		if ((target = $(this).attr('href')) || (target = $(this).attr('url'))) {
			$.get(target).success(function(data) {
				//var data = JSON.parse(data);
				if (data.code == 1) {
					if (data.url) {
						updateAlert(data.msg + ' 页面即将自动跳转~', 'success');
					} else {
						updateAlert(data.msg, 'success');
					}
					setTimeout(function() {
						if (data.url) {
							location.href = data.url;
						} else if ($(that).hasClass('no-refresh')) {
							$('#top-alert').find('button').click();
						} else {
							location.reload();
						}
					}, 1500);
				} else {
					updateAlert(data.msg);
					setTimeout(function() {
						//location.reload();
						// if (data.url) {
						// 	location.href = data.url;
						// } else {
						// 	$('#top-alert').find('button').click();
						// }
					}, 1500);
				}
			});

		}
		return false;
	});

	//ajax post submit请求
	$('.ajax-post').click(function() {
		var target, query, form;
		var target_form = $(this).attr('target-form');
		var that = this;
		var nead_confirm = false;
		if (($(this).attr('type') == 'submit') || (target = $(this).attr('href')) || (target = $(this).attr('url'))) {
			form = $('.' + target_form);

			if ($(this).attr('hide-data') === 'true') { //无数据时也可以使用的功能
				form = $('.hide-data');
				query = form.serialize();
			} else if (form.get(0) == undefined) {
				return false;
			} else if (form.get(0).nodeName == 'FORM') {
				if ($(this).hasClass('confirm')) {
					if (!confirm('确认要执行该操作吗?')) {
						return false;
					}
				}
				if ($(this).attr('url') !== undefined) {
					target = $(this).attr('url');
				} else {
					target = form.get(0).action;
				}
				query = form.serialize();
			} else if (form.get(0).nodeName == 'INPUT' || form.get(0).nodeName == 'SELECT' || form.get(0).nodeName == 'TEXTAREA') {
				form.each(function(k, v) {
					if (v.type == 'checkbox' && v.checked == true) {
						nead_confirm = true;
					}
				})
				if (nead_confirm && $(this).hasClass('confirm')) {
					if (!confirm('确认要执行该操作吗?')) {
						return false;
					}
				}
				query = form.serialize();
			} else {
				if ($(this).hasClass('confirm')) {
					if (!confirm('确认要执行该操作吗?')) {
						return false;
					}
				}
				query = form.find('input,select,textarea').serialize();
			}
			$(that).addClass('disabled').attr('autocomplete', 'off').prop('disabled', true);
			$.post(target, query).success(function(data) {
				//var data = JSON.parse(data);
				if (data.code == 1) {
					if (data.url) {
						updateAlert(data.msg + ' 页面即将自动跳转~', 'success');
					} else {
						updateAlert(data.msg, 'success');
					}
					setTimeout(function() {
						$(that).removeClass('disabled').prop('disabled', false);
						if (data.url) {
							location.href = data.url;
						} else if ($(that).hasClass('no-refresh')) {
							$('#top-alert').find('button').click();
						} else {
							location.reload();
						}
					}, 1500);
				} else {
					updateAlert(data.msg, 'danger');
					setTimeout(function() {
						$(that).removeClass('disabled').prop('disabled', false);
						//location.reload();
						// if (data.url) {
						// 	location.href = data.url;
						// } else {
						// 	$('#top-alert').find('button').click();
						// }
					}, 1500);
				}
			});
		}
		return false;
	});

	window.updateAlert = function(text, c) {
		if (typeof c != 'undefined') {
			var msg = $.messager.show(text, {
				placement: 'bottom',
				type: c
			});
		} else {
			var msg = $.messager.show(text, {
				placement: 'bottom'
			})
		}
		msg.show();
	};
});

/**
 * 置顶函数
 * @param  {[type]} obj [description]
 * @return {[type]}     [description]
 */
function go_to_top(obj){
	var scrTop = $(window).scrollTop();
	var windowTop = $(window).height();
	if ((windowTop-300)<scrTop){
		$("#"+obj).fadeIn("slow");
	}else{
		$("#"+obj).fadeOut("slow");
	}	
}

var load = {
	script: function(xyUrl, callback){
		var head = document.getElementsByTagName('head')[0];
		var script = document.createElement('script');
		script.type = 'text/javascript';
		script.src = xyUrl;
		//借鉴了jQuery的script跨域方法
		script.onload = script.onreadystatechange = function() {
			if ((!this.readyState || this.readyState === "loaded" || this.readyState === "complete")) {
				callback && callback();
				// Handle memory leak in IE
				script.onload = script.onreadystatechange = null;
				if (head && script.parentNode) {
					head.removeChild(script);
				}
			}
		};
		// Use insertBefore instead of appendChild  to circumvent an IE6 bug.
		head.insertBefore(script, head.firstChild);
	},
	css: function(xyUrl, callback){
		var head = document.getElementsByTagName('head')[0];
		var link = document.createElement('link');
		link.type = 'text/css';
		link.rel = 'stylesheet';
		link.src = xyUrl;
		//借鉴了jQuery的script跨域方法
		link.onload = link.onreadystatechange = function() {
			if ((!this.readyState || this.readyState === "loaded" || this.readyState === "complete")) {
				callback && callback();
				// Handle memory leak in IE
				link.onload = link.onreadystatechange = null;
				if (head && link.parentNode) {
					head.removeChild(link);
				}
			}
		};
		// Use insertBefore instead of appendChild  to circumvent an IE6 bug.
		head.insertBefore(link, head.firstChild);
	}
}