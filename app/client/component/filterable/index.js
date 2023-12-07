import domd from 'domd'
import nav from 'lib/navigation'

export default element => {
	const d = domd(element)
	const slug = element.getAttribute('data-url')
	const key = element.getAttribute('data-key')
	const checkboxes = element.querySelectorAll('li')
	d.on('keyup', 'input[type="text"]', (ev, el) => {
		const value = el.value.toLowerCase()

		checkboxes.forEach(function(item) {
			const label = item.querySelector('label').textContent.toLowerCase();
	    if (label.indexOf(value) > -1) {
        item.style.display = '';
	    } else {
        item.style.display = 'none';
	    }
		})
	})

	d.on('change', 'select', (ev, el) => {
		const option = el.options[el.selectedIndex]
		let query = nav.removeParam(location.search, key)
		nav.load(slug + '?' + query + ';' + key + '=' + option.value)
	})

	d.on('click', 'a', (ev, el) => {
		checkboxes.forEach(function(item) {
			item.classList.remove('active')
		})
		el.parentElement.classList.add('active')
		let query = nav.removeParam(location.search, `filters[${key}][]`)
		const value = el.getAttribute('data-value')
		nav.load(slug + '?' + query + ';filters[' + key + ']=' + value)
		return false
	})

	d.on('click', 'input[type="checkbox"]', (ev, el) => {
		let filters = []
		const checkboxes = element.querySelectorAll('input[type="checkbox"]')
	  checkboxes.forEach(checkbox => {
      if (checkbox.checked) {
        filters.push(`filters[${key}][]=${encodeURIComponent(checkbox.value)}`)
      }
	  })

	  const filters_query = filters.join(';')
	  let query = nav.removeParam(location.search, `filters[${key}][]`)
		nav.load(slug + '?' + query + (filters_query ? ';' + filters_query : ''))
	})


	d.on('click', 'input[type="radio"]', (ev, el) => {
		const value = element.querySelector('input[type="radio"]:checked').value
		let query = nav.removeParam(location.search, `filters[${key}]`)
		nav.load(slug + '?' + query + (value ? ';' + 'filters[' + key + ']=' + value : ''))
	})


	return () => {}
}
