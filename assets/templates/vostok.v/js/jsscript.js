var fbjQuery = jQuery.noConflict();
	
	fbjQuery(document).ready(function() {
	  fbjQuery("a[rel=example_group]").fancybox({
	    'helpers': {
	      'overlay': {
	        'locked': false
	      }
	    },
	    'openEffect': 'elastic',
	    'closeEffect': 'elastic',
	    'titlePosition': 'over',
	    'titleFormat': function(title, currentArray, currentIndex, currentOpts) {
	      return '<span id="fancybox-title-over">Ôîòî ' + (currentIndex + 1) + ' / ' + currentArray.length + (title.length ? ' &nbsp; ' + title : '') + '</span>';
	    }
	  });
	});

	fbjQuery(window).load(function() {
	  fbjQuery('.flexslider').flexslider({
	    animation: "slide",
	    controlsContainer: fbjQuery(".custom-controls-container")
	  });
	});

	fbjQuery('.carousel').bxSlider({
		auto: true,
	  slideWidth: 220,
	  minSlides: 1,
	  maxSlides: 5,
	  slideMargin: 17
	});

	var map_cont = fbjQuery('.mapbox');
	if (map_cont.length) {
	  initMap();
	}

	// Карта завода
	function initMap() {

	  // Specify features and elements to define styles.
	  var orenFactStyle = [{
	    featureType: "all",
	    stylers: [
	      { saturation: -80 }
	    ]
	  }, {
	    featureType: "road.arterial",
	    elementType: "geometry",
	    stylers: [
	      { hue: "#00ffee" },
	      { saturation: 50 }
	    ]
	  }, {
	    featureType: "poi.business",
	    elementType: "labels",
	    stylers: [
	      { visibility: "off" }
	    ]
	  }];

	  var myLatlng = new google.maps.LatLng(51.788681, 55.145966);
	  var mapOpts = {
	    center: myLatlng,
	    scrollwheel: false,
	    zoom: 17,
	    mapTypeControl: true,
	    scaleControl: true,
	    streetViewControl: false
	  }
	  var map = new google.maps.Map(document.getElementById('map'), mapOpts);

	  var marker = new google.maps.Marker({
	    position: myLatlng,
	    map: map,
	    title: "Оренбургский Пуховой Платок"
	  });
	}

