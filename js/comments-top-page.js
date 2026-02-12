(function($){
  'use strict';

    /* =========================================================
     COMMON SAFE HELPERS
  ========================================================= */

function setupSlider(){
  var $stack = $('.interview-stack');
  var $prev  = $('.slide-interview-btn.prev');
  var $next  = $('.slide-interview-btn.next');
  if(!$stack.length) return;

  function visibleCount(){
    var s = $stack.get(0);
    if(!s) return 1;
    var cs = window.getComputedStyle(s);
    var v  = parseInt((cs.getPropertyValue('--items') || '1'), 10);
    return isNaN(v) ? 1 : Math.max(1, v);
  }

  
  function step(){
    var s = $stack.get(0);
    var $items = $stack.find('.stack-item');

    
    if(!s || !$items.length) return $stack.width() || 0;

    
    if($items.length >= 2){
      var r1 = $items.get(0).getBoundingClientRect();
      var r2 = $items.get(1).getBoundingClientRect();
      var delta = r2.left - r1.left;
      if(delta > 0) return delta;
    }

    
    var r0 = $items.get(0).getBoundingClientRect();
    return r0.width || 0;
  }

  function itemCount(){ return $stack.find('.stack-item').length; }

  function maxLeft(){
    var s = $stack.get(0);
    return s ? Math.max(0, s.scrollWidth - s.clientWidth) : 0;
  }

  function maxIndex(){
    return Math.max(0, itemCount() - visibleCount());
  }

  function curIndex(){
    var s  = $stack.get(0);
    var st = step();              
    if(!s || !st) return 0;

    var idx = Math.round(s.scrollLeft / st);
    idx = Math.max(0, Math.min(idx, maxIndex()));
    return idx;
  }

  function updateBtns(){
    var s = $stack.get(0);
    if(!s) return;
    var idx = curIndex();
    $prev.prop('disabled', idx <= 0);
    $next.prop('disabled', idx >= maxIndex());
  }

  function scrollToIndex(i, smooth){
    var s = $stack.get(0);
    if(!s) return;

    var st = step();
    if(!st) return;

    var idx  = Math.max(0, Math.min(i, maxIndex()));
    var left = idx * st;

    left = Math.max(0, Math.min(left, maxLeft()));

    s.scrollTo({ left: left, behavior: smooth ? 'smooth' : 'auto' });

    updateBtns();
    setTimeout(updateBtns, 350);
  }

  $prev.off('.staff').on('click.staff', function(e){
    e.preventDefault();
    scrollToIndex(curIndex() - 1, true);
  });

  $next.off('.staff').on('click.staff', function(e){
    e.preventDefault();
    scrollToIndex(curIndex() + 1, true);
  });

  $stack.off('.staff').on('scroll.staff', updateBtns);

  
  var rafId = 0;
  function refresh(){
    cancelAnimationFrame(rafId);
    rafId = requestAnimationFrame(function(){
      scrollToIndex(curIndex(), false);
      updateBtns();
    });
  }

  $(window).off('resize.staff').on('resize.staff', refresh);

  $stack.find('img').each(function(){
    if(!this.complete) $(this).one('load error', refresh);
  });

  refresh();
}


  

function setupPopups(){
  var closeTimer = null;

  function cancelClose(){ clearTimeout(closeTimer); }

  function scheduleClose(){
    clearTimeout(closeTimer);
    closeTimer = setTimeout(function(){
      var overItem  = $('.interview-stack .stack-item:hover').length > 0;
      var overPopup = $('.interview-popup-overlay:hover').length > 0;
      if(!overItem && !overPopup){
        $('.interview-popup-overlay').removeClass('active');
        $('body').removeClass('popup-open');
      }
    }, 180);
  }

  
  function positionPopup($item, $overlay){
    var rect = $item.get(0).getBoundingClientRect();
    var $content = $overlay.find('.popup-content');

    
    var cw = $content.outerWidth();
    var ch = $content.outerHeight();

    
    var x = rect.left + rect.width * 0.5;
    var y = rect.top  + rect.height * 0.5;

    
    var pad = 12;
    var minX = pad + cw/2;
    var maxX = window.innerWidth  - pad - cw/2;
    var minY = pad + ch/2;
    var maxY = window.innerHeight - pad - ch/2;

    x = Math.max(minX, Math.min(maxX, x));
    y = Math.max(minY, Math.min(maxY, y));

    
    $content.css({
      left: x + 'px',
      top:  y + 'px',
      transform: 'translate(-50%, -50%)' 
    });
  }

  function openPopup(sel, $item){
    if(!sel) return;
    var $overlay = $(sel);

    $('.interview-popup-overlay').removeClass('active');
    $overlay.addClass('active');
    $('body').addClass('popup-open');

    
    requestAnimationFrame(function(){
      positionPopup($item, $overlay);
    });
  }

  
  $(document)
    .off('mouseenter.staffHoverItem mouseleave.staffHoverItem')
    .on('mouseenter.staffHoverItem', '.interview-stack .stack-item', function(){
      cancelClose();
      var sel = $(this).data('popup');
      openPopup(sel, $(this));
    })
    .on('mouseleave.staffHoverItem', '.interview-stack .stack-item', function(){
      scheduleClose();
    });

  
  $(document)
    .off('mouseenter.staffHoverPopup mouseleave.staffHoverPopup')
    .on('mouseenter.staffHoverPopup', '.interview-popup-overlay', function(){
      cancelClose();
    })
    .on('mouseleave.staffHoverPopup', '.interview-popup-overlay', function(){
      scheduleClose();
    });

  
  $(window).off('resize.staffPop scroll.staffPop').on('resize.staffPop scroll.staffPop', function(){
    var $active = $('.interview-popup-overlay.active');
    if(!$active.length) return;

    
    var $item = $('.interview-stack .stack-item:hover').first();
    if($item.length){
      positionPopup($item, $active);
    }
  });

  
  $(document)
    .off('click.staffPopupGo')
    .on('click.staffPopupGo', '.interview-popup-overlay.active .popup-frame-bg, .interview-popup-overlay.active .popup-frame > a', function(e){
      var $overlay = $(this).closest('.interview-popup-overlay');
      var href = ($overlay.find('.popup-name').attr('href') || $(this).closest('a').attr('href') || '').trim();
      if(href) window.location.href = href;
    });

  
  $(document).off('keydown.staffEsc').on('keydown.staffEsc', function(e){
    if(e.key === 'Escape'){
      $('.interview-popup-overlay').removeClass('active');
      $('body').removeClass('popup-open');
    }
  });
}




  $(function(){
    setupSlider();
    setupPopups();
  });

})(jQuery);
