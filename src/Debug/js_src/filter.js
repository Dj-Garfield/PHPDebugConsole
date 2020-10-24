/**
 * Filter entries
 */

import $ from 'jquery'

var channels = []
var tests = [
  function ($node) {
    var channel = $node.data('channel') || $node.closest('.debug').data('channelNameRoot')
    return channels.indexOf(channel) > -1
  }
]
var preFilterCallbacks = [
  function ($root) {
    var $checkboxes = $root.find('input[data-toggle=channel]')
    /*
    channels = $checkboxes.length
      ? []
      : [undefined]
    */
    if ($checkboxes.length === 0) {
      channels = [$root.data('channelNameRoot')]
      return
    }
    channels = []
    $checkboxes.filter(':checked').each(function () {
      channels.push($(this).val())
      // if ($(this).data('isRoot')) {
      //   channels.push(undefined)
      // }
    })
  }
]

export function init ($delegateNode) {
  applyFilter($delegateNode)

  $delegateNode.on('change', 'input[type=checkbox]', function () {
    var $this = $(this)
    var isChecked = $this.is(':checked')
    var $nested = $this.closest('label').next('ul').find('input')
    var $root = $this.closest('.debug')
    if ($this.data('toggle') === 'error') {
      // filtered separately
      return
    }
    $nested.prop('checked', isChecked)
    applyFilter($root)
    updateFilterStatus($root)
  })

  $delegateNode.on('change', 'input[data-toggle=error]', function () {
    var $this = $(this)
    var $root = $this.closest('.debug')
    var errorClass = $this.val()
    var isChecked = $this.is(':checked')
    var selector = '.group-body .error-' + errorClass
    $root.find(selector).toggleClass('filter-hidden', !isChecked)
    // trigger collapse to potentially update group icon
    $root.find('.m_error, .m_warn').parents('.m_group')
      .trigger('collapsed.debug.group')
    updateFilterStatus($root)
  })

  $delegateNode.on('channelAdded.debug', function (e) {
    var $root = $(e.target).closest('.debug')
    updateFilterStatus($root)
  })
}

export function addTest (func) {
  tests.push(func)
}

export function addPreFilter (func) {
  preFilterCallbacks.push(func)
}

function applyFilter ($root) {
  var i
  var i2
  var len
  var sort = []
  for (i in preFilterCallbacks) {
    preFilterCallbacks[i]($root)
  }
  /*
    find all log entries and process them greatest depth to least depth
  */
  $root.find('> .debug-tabs > .tab-primary > .tab-body')
    .find('.m_alert, .group-body > *:not(.m_groupSummary)')
    .each(function () {
      sort.push({
        depth: $(this).parentsUntil('.tab_body').length,
        node: $(this)
      })
    })
  sort.sort(function (a, b) {
    return a.depth < b.depth ? 1 : -1
  })
  for (i = 0, len = sort.length; i < len; i++) {
    var $node = sort[i].node
    var show = true
    var unhiding = false
    if ($node.data('channel') === 'general.phpError') {
      // php Errors are filtered separately
      continue
    }
    for (i2 in tests) {
      show = tests[i2]($node)
      if (!show) {
        break
      }
    }
    unhiding = show && $node.is('.filter-hidden')
    $node.toggleClass('filter-hidden', !show)
    if (unhiding && $node.is(':visible')) {
      $node.debugEnhance()
    }
  }
}

function updateFilterStatus ($debugRoot) {
  var haveUnchecked = $debugRoot.find('.debug-sidebar input:checkbox:not(:checked)').length > 0
  $debugRoot.toggleClass('filter-active', haveUnchecked)
}
