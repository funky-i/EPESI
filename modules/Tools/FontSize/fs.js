isDigit = function(num) {
	if (num.length>1){ return false; }
	var string = "1234567890";
	if (string.indexOf(num) != -1){ return true; }
	return false;
}

Tools_FontSize_changeFontSize = function( delta ) {
	var currentSize = document.body.style.fontSize;
	var newSize = "100";
	if( currentSize ) {
		var size = '';
		for( i = 0; i < currentSize.length; i++) {
			if( isDigit(currentSize.charAt(i)) ) 
				size += currentSize.charAt(i)
		}
		if( currentSize.indexOf("%") != -1 ) {
			currentSize = size * 1;
		} else {
			currentSize = 100;
		}
	} else {
		currentSize = 100;
	}
	newSize = (currentSize + delta);
	if(newSize < 10) { newSize = 10; }
	document.body.style.fontSize = newSize + "%";
}
