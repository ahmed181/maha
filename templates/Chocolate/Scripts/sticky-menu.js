$(window).scroll(function(){
    $("#Menu").css("top",Math.max(40,200-$(this).scrollTop()));
});