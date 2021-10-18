define(['jquery', 'sent', 'form', 'clipboard'], function($, sent, form, clipboard){
	var domain = {
		config: {
			is_select_domain: false,
			is_select_subpath: false,
		},
		generate: function(){
			domain.init()
			//操作域名验证
			$('.check-btn').click(function(){
				if($('input[name=domain]').val() == ''){
					sent.msg('操作域名为空!', 'info')
					return false;
				}
				$.ajax({
					url    : '/user/generate/verify',
					data   : {domain: $('input[name=domain]').val()},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg, 'info');
							$('input[name=domain]').val(res.data.domain)
							$('input[name=domain]').attr('data-id', res.data.id)
							domain.config.is_select_domain = true;
							$('input[name=created_files],select[name=sub_path]').val('');
							domain.resetData();
							domain.init()
							domain.setSubDomain(res.data.id)
						}else{
							sent.msg(res.msg, 'error');
						}
					},
					dataType: 'json'
				})
			})
			$('input[name=domain]').change(function(){
				domain.config.is_select_domain = false;
				domain.config.is_select_subpath = false;
				$('input[name=created_files],select[name=sub_path]').val('');
				domain.resetData();
				domain.init()
			})
			//切换操作域名
			$('select[name=domain]').change(function(){
				if($('input[name=domain]').value() != ''){
					domain.config.is_select_domain = true;
					$('input[name=created_files],select[name=sub_path]').val('');
					domain.init()
					domain.resetData();
					domain.setSubDomain(0)
				}
			})
			//创建二级目录
			$('.created-btn').click(function(){
				if($('input[name=domain]').data('id') == ''){
					sent.msg('操作域名为空!', 'info')
					return false;
				}
				if($('input[name=created_files]').val() == ''){
					sent.msg('子目录名称为空!', 'info')
					return false;
				}
				$.ajax({
					url    : '/user/generate/createpath',
					data   : {domain_id: $('input[name=domain]').data('id'), sub_path: $('input[name=created_files]').val()},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg)
							domain.setSubDomain(0)
						}else{
							sent.msg(res.msg)
						}
					},
					dataType: 'json'
				})
			})

			//切换二级目录
			$('select[name=sub_path]').change(function(){
				if($('select[name=sub_path] option:selected').attr('value') != ''){
					//获取当前目录信息
					$('form.goods-form input,form.goods-form select').val('');
					$('input[name=spide_url],input[name=301],input[name=title],input[name=keyword],input[name=description]').val('');
					$('.generate_link').attr('src', '')
					$('.copy_pixel_url').html('')
					domain.init()
					domain.setDnsInfo()
				}
			})
			//删除二级目录
			$('.delete_sub_path').click(function(){
				if($('select[name=sub_path] option:selected').attr('value') != ''){
					//获取当前目录信息
					$.ajax({
						url     : '/user/generate/delsubpath',
						data    : {dns_id: $('select[name=sub_path] option:selected').attr('value')},
						success : function(res){
							if(res.code == 1){
								domain.setSubDomain(0);
							}
							sent.msg(res.msg)
						},
						dataType: 'json'
					})
				}else{
					sent.msg('未选择二级目录', 'info');
				}
			})

			//采集
			$('.spide-btn').click(function(){
				if($('input[name=spide_url]').val() == ''){
					sent.msg('采集地址不能为空!', 'info')
					return false;
				}
				let sub_path = $('select[name=sub_path] option:selected').text() ? $('select[name=sub_path] option:selected').text() : $('input[name=created_files]').val();
				$.ajax({
					url    : '/user/generate/spide_content',
					data   : {proxy_site: $('input[name=spide_url]').val(), domain_id: $('input[name=domain]').data('id'), sub_path: sub_path},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg)
							$('iframe').attr('src', domain.getGenerateLink())
						}
					},
					dataType: 'json'
				})
			})
			$('.generate_301').click(function(){
				if($('input[name=301]').val() == ''){
					sent.msg('301地址不能为空!', 'info')
					return false;
				}
				$('iframe').attr('src', $('input[name=spide_url]').val())
				let sub_path = $('select[name=sub_path] option:selected').text() ? $('select[name=sub_path] option:selected').text() : $('input[name=created_files]').val();
				$.ajax({
					url    : '/user/generate/set301',
					data   : {proxy_site: $('input[name=301]').val(), domain_id: $('input[name=domain]').data('id'), sub_path: sub_path},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg)
							$('iframe').attr('src', domain.getGenerateLink())
						}
					},
					dataType: 'json'
				})
			})
			//复制链接
			
			//注入mate
			$('.inject_btn').click(function(){
				if($('input[name=title]').val() == ''){
					sent.msg('注入标题不能为空!', 'info')
					return false;
				}
				if($('input[name=keyword]').val() == ''){
					sent.msg('注入关键词不能为空!', 'info')
					return false;
				}
				if($('input[name=description]').val() == ''){
					sent.msg('注入描述不能为空!', 'info')
					return false;
				}
				$.ajax({
					url    : '/user/generate/injectTitle',
					data   : {
						dns_id: $('select[name=sub_path] option:selected').val(),
						proxy_site: $('input[name=spide_url]').val(),
						title: $('input[name=title]').val(),
						keyword: $('input[name=keyword]').val(),
						description: $('input[name=description]').val()
					},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg, 'info')
						}else{
							sent.msg(res.msg, 'error')
						}
					},
					dataType: 'json'
				})
			})
			//商品分类变动后，产品更新
			$('select[name=cate_id]').change(function(){
				domain.setCategoryChage($('select[name=cate_id] option:selected').val(), 0);
			})
			$('button.submit-btn').click(function(){
				$.ajax({
					url: '/user/generate/updatedns',
					data: $('form').serialize(),
					type: 'post',
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg, 'info');
							$('iframe').attr('src', $('.generate_link').attr('href'));
							domain.setDnsInfo();
						}else{
							sent.msg(res.msg, 'error');
						}
					},
					dataType: 'json'
				})
			})
			$('.toggle-btn').click(function(e){
				var type = $(this).attr('role');
				$.ajax({
					url: '/user/generate/togglepage',
					data: {type: type, dns_id: $('select[name=sub_path] option:selected').val(), campaign_signature: $('input[name=campaign_signature]').val(), campaign_id: $('input[name=campaign_id]').val()},
					success: function(res){
						if(res.code == 1){
							sent.msg(res.msg, 'info');
							$('iframe').attr('src', $('.generate_link').attr('href'));
						}else{
							sent.msg(res.msg, 'error');
						}
					},
					dataType: 'json'
				})
			})
		},
		init: function(){
			if(!domain.config.is_select_domain){
				$('.created_files_box').hide()
				$('.sub_path_box').hide()
			}else{
				$('.created_files_box').show()
				$('.sub_path_box').show()
			}
			if(!domain.config.is_select_subpath){
				$('.toggle-btn').attr({"disabled":"disabled"});
				$('form.goods-form .submit-btn,form.goods-form input,form.goods-form select').attr({"disabled":"disabled"});
				$('.sub_info_box input,.sub_info_box button').attr({"disabled":"disabled"});
			}else{
				$('.toggle-btn').removeAttr("disabled");
				$('form.goods-form .submit-btn,form.goods-form input,form.goods-form select').removeAttr("disabled");
				$('.sub_info_box input,.sub_info_box button').removeAttr("disabled");
			}
		},
		resetData: function(){
			$('form.goods-form input,form.goods-form select').val('');
			$('input[name=spide_url],input[name=301],input[name=title],input[name=keyword],input[name=description]').val('');
			$('.generate_link').attr('src', '');
			$('.generate_link span').html('');
			$('.copy_pixel_url').html('')
		},
		setSubDomain: function(id){
			var id = id ? id : $('input[name=domain]').data('id');
			$('select[name=sub_path] option[value!=""]').remove();
			$.ajax({
				url    : '/user/generate/dns',
				data   : {domain: id},
				success: function(res){
					if(res.code == 1){
						res.data.map(function(item){
							if($('input[name=created_files]').val() == item.sub_domain){
								$('select[name=sub_path]').append('<option value="'+item.id+'" selected>'+item.sub_domain+'</option>')
							}else{
								$('select[name=sub_path]').append('<option value="'+item.id+'">'+item.sub_domain+'</option>')
							}
						})
						domain.setDnsInfo();
					}
				},
				dataType: 'json'
			})
		},
		setDnsInfo: function(){
			if($('select[name=sub_path] option:selected').val() == '' || $('input[name=domain]').data('id') == ''){
				return false;
			}
			var link = domain.getGenerateLink();
			$('.generate_link span').html(link);
			$('.generate_link').attr('href', link);
			$('iframe').attr('src', link);
			var copy = new clipboard('.copy',{
				text:function(trigger) {
					return $('.generate_link').attr('href');
				}
			});
			copy.on('success', function(){
				sent.msg('已复制', 'info');
			})
			$.ajax({
				url: '/user/generate/dnsinfo',
				data: {dns_id: $('select[name=sub_path] option:selected').attr('value')},
				success: function(res){
					if(res.code == 1){
						domain.config.is_select_subpath = true;
						domain.init();
						var data = res.data;
						$('input[name=title]').val(data.title);
						$('input[name=keyword]').val(data.keyword);
						$('input[name=description]').val(data.description);
						if(data.proxy_method == '301'){
							$('input[name=301]').val(data.proxy_site);
							$('input[name=spide_url]').val('');
						}else{
							$('input[name=spide_url]').val(data.proxy_site);
							$('input[name=301]').val('');
						}
						$('select[name=cate_id]').val(data.cate_id);
						domain.setCategoryChage(data.cate_id, data.offer_sku);
						$('select[name=template]').val(data.lp_template);
						$('input[name=offer_url]').val(data.offer_url);
						$('input[name=ads_no]').val(data.ads_no);
						$('input[name=pixel_type]').val(data.pixel_type);
						$('input[name=pixel_id]').val(data.pixel_id);
						$('div[role=pixel_url]').text('<iframe src="'+data.pixel_url+'" frameborder=0 width=1 height=1></iframe>');
						$('input[name=campaign_signature]').val(data.campaign_signature);
						$('input[name=campaign_id]').val(data.campaign_id);
						var copy = new clipboard('.copy_pixel_url',{
							text:function(trigger) {
								return '<iframe src="'+data.pixel_url+'" frameborder=0 width=1 height=1></iframe>';
							}
						});
						copy.on('success', function(){
							sent.msg('已复制', 'info');
						})
					}
				},
				dataType: 'json'
			})
		},
		setCategoryChage: function(category_id, selected){
			$('select[name=offer_sku] option[value!=""]').remove();
			$.ajax({
				url    : '/user/goods/index',
				data   : {category_id: category_id},
				success: function(res){
					res.data.map(function(item, i){
						if(item.id == selected){
							$('select[name=offer_sku]').append('<option value="'+item.id+'" selected>'+item.title+'</option>')
						}else{
							$('select[name=offer_sku]').append('<option value="'+item.id+'">'+item.title+'</option>')
						}
					})
				},
				dataType: 'json'
			})
		},
		getGenerateLink: function(){
			return 'https://www.' + $('input[name=domain]').val() + '/' + $('select[name=sub_path] option:selected').text();
		}
	}

	return domain;
})