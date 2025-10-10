(function(){
	// Theme toggle helper
	window.toggleTheme = function(){
		try{
			var html = document.documentElement;
			var dark = html.classList.toggle('dark');
			localStorage.setItem('theme', dark ? 'dark' : 'light');
		}catch(e){}
	};

	// SweetAlert2 convenience: toast and confirm
	window.uiToast = function(message, type){
		if (!window.Swal) return alert(message);
		const Toast = Swal.mixin({
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 2200,
			timerProgressBar: true,
			customClass: { popup: 'rounded-xl shadow-lg' }
		});
		Toast.fire({ icon: type || 'success', title: message });
	};
	window.uiConfirm = function(opts){
		if (!window.Swal) return Promise.resolve({ isConfirmed: confirm(opts && opts.text || 'Continue?') });
		return Swal.fire({
			title: (opts && opts.title) || 'Are you sure?',
			text: (opts && opts.text) || '',
			icon: (opts && opts.icon) || 'question',
			showCancelButton: true,
			confirmButtonText: (opts && opts.confirmText) || 'Confirm',
			cancelButtonText: (opts && opts.cancelText) || 'Cancel',
			buttonsStyling: false,
			reverseButtons: true,
			customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }
		});
	};
})();


