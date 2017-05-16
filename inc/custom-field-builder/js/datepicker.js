/*-------------------------------------------*/
/*	datepicker
/*-------------------------------------------*/
jQuery(document).ready(function($){
	 //datepickerというinputタグにDatepicker
	 $('.datepicker').datepicker({
		// showOn: 'button',
		// buttonImage: '/images/calendar.gif',
		// buttonImageOnly: true,
		dateFormat: 'yymmdd',
		// changeMonth: true,
		// changeYear: true,
		// yearRange: '1960:2009',
		// showMonthAfterYear: false,
		firstDay: 1
	});
});
