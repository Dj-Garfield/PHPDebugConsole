import $ from "jquery";
import {lsGet,lsSet} from "./http.js";
import {addTest as addFilterTest, addPreFilter} from "./filter.js";

var options;
var methods;	// method filters
var $root;

export function init($debugRoot, opts) {
	$root = $debugRoot;
	options = opts;

	if (!opts.sidebar) {
		return;
	}

	addMarkup();

	if (options.persistDrawer && !lsGet("phpDebugConsole-openSidebar")) {
		close();
	}

	addPreFilter(function($root){
		methods = [];
		$root.find("input[data-toggle=method]:checked").each(function(){
			methods.push($(this).val());
		});
	});

	addFilterTest(function($node){
		var method = $node[0].className.match(/\bm_(\S+)\b/)[1];
		if (["alert","error","warn","info"].indexOf(method) > -1) {
			return methods.indexOf(method) > -1;
		} else {
			return methods.indexOf("other") > -1;
		}
	});

	$root.find(".sidebar-toggle").on("click", function() {
		var isVis = $(".debug-sidebar").is(".show");
		if (!isVis) {
			open();
		} else {
			close();
		}
	});

	$root.find(".debug-sidebar input[type=checkbox]").on("change", function(e) {
		var $input = $(this),
			$toggle = $input.closest(".toggle"),
			$nested = $toggle.next("ul").find(".toggle"),
			isActive = $input.is(":checked");
		$toggle.toggleClass("active", isActive);
		$nested.toggleClass("active", isActive);
		if ($input.val() == "error-fatal") {
			$(".m_alert.error-summary").toggle(!isActive);
		}
	});
}

function addMarkup() {
	var $sidebar = $('<div class="debug-sidebar show no-transition"></div>');
	$sidebar.html('\
		<div class="sidebar-toggle">\
			<div class="collapse">\
				<i class="fa fa-caret-left"></i>\
				<i class="fa fa-ellipsis-v"></i>\
				<i class="fa fa-caret-left"></i>\
			</div>\
			<div class="expand">\
				<i class="fa fa-caret-right"></i>\
				<i class="fa fa-ellipsis-v"></i>\
				<i class="fa fa-caret-right"></i>\
			</div>\
		</div>\
		<ul class="list-unstyled debug-filters">\
			<li class="php-errors">\
				<span><i class="fa fa-fw fa-lg fa-code"></i> PHP Errors</span>\
				<ul class="list-unstyled">\
				</ul>\
			</li>\
			<li class="channels">\
				<span><i class="fa fa-fw fa-lg fa-list-ul"></i> Channels</span>\
				<ul class="list-unstyled">\
				</ul>\
			</li>\
			<li><label class="toggle active"><input type="checkbox" checked data-toggle="method" value="alert"><i class="fa fa-fw fa-lg fa-bullhorn"></i> Alerts</label></li>\
			<li><label class="toggle active"><input type="checkbox" checked data-toggle="method" value="error"><i class="fa fa-fw fa-lg fa-times-circle"></i> Error</label></li>\
			<li><label class="toggle active"><input type="checkbox" checked data-toggle="method" value="warn"><i class="fa fa-fw fa-lg fa-warning"></i> warning</label></li>\
			<li><label class="toggle active"><input type="checkbox" checked data-toggle="method" value="info"><i class="fa fa-fw fa-lg fa-info-circle"></i> Info</label></li>\
			<li><label class="toggle active"><input type="checkbox" checked data-toggle="method" value="other"><i class="fa fa-fw fa-lg fa-sticky-note-o"></i> Other</label></li>\
		</ul>\
	');
	$root.find(".debug-body").before($sidebar);

	phpErrorToggles();
	moveChannelToggles();
	moveExpandAll();

	setTimeout(function(){
		$sidebar.removeClass("no-transition");
	}, 500);
}

/**
 * grab the .debug-body toggles and move them to sidebar
 */
function moveChannelToggles() {
	var $togglesSrc = $root.find(".debug-body .channels > ul > li"),
		$togglesDest = $root.find(".debug-sidebar .channels ul");
	$togglesSrc.find("label").addClass("toggle active");
	$togglesDest.append($togglesSrc);
	if ($togglesDest.children().length === 0) {
		$togglesDest.parent().hide();
	}
	$root.find(".debug-body .channels").remove();
}

/**
 * Grab the .debug-body "Expand All" and move it to sidebar
 */
function moveExpandAll() {
	var $btn = $root.find(".debug-body > .expand-all"),
		html = $btn.html();
	$btn.html(html.replace('Expand', 'Exp'));
	$btn.appendTo($root.find(".debug-sidebar"));
}

/**
 * Grab the error toggles from .debug-body's error-summary move to sidebar
 */
function phpErrorToggles() {
	var $togglesUl = $root.find(".debug-sidebar .php-errors ul"),
		$errorSummary = $root.find(".m_alert.error-summary"),
		haveFatal = $root.find(".m_error.error-fatal").length > 0;
	if (haveFatal) {
		$togglesUl.append('<li class="toggle active"><label>\
			<input type="checkbox" checked data-toggle="error" value="error-fatal" />fatal <span class="badge">1</span>\
			</label></li>');
	}
	$errorSummary.find("label").each(function(){
		var $li = $(this).parent().addClass("toggle active"),
			$checkbox = $(this).find("input"),
			val = $checkbox.val().replace("error-", ""),
			html = "<label>" + $checkbox[0].outerHTML + val + ' <span class="badge">' + $checkbox.data("count") + "</span></label>";
		$li.html(html);
		$togglesUl.append($li);
	});
	if ($togglesUl.children().length === 0) {
		$togglesUl.parent().hide();
	}
	if (!haveFatal) {
		$errorSummary.remove();
	} else {
		$errorSummary.find("h3").eq(1).remove();
	}
}

function open() {
	$(".debug-sidebar").addClass("show");
	lsSet("phpDebugConsole-openSidebar", true);
}

function close() {
	$(".debug-sidebar").removeClass("show");
	lsSet("phpDebugConsole-openSidebar", false);
}
