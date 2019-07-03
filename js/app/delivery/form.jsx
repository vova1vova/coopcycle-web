import MapHelper from '../MapHelper'
import AddressAutosuggest from '../components/AddressAutosuggest'
import React from 'react'
import { render } from 'react-dom'
import _ from 'lodash'
import axios from 'axios'
import moment from 'moment'

let map
let jwt

let markers = {
  pickup: null,
  dropoff: null,
}

function disableForm() {
  $('#delivery-submit').attr('disabled', true)
  $('#loader').removeClass('hidden')
}

function enableForm() {
  $('#delivery-submit').attr('disabled', false)
  $('#loader').addClass('hidden')
}

function refreshRouting() {

  // We need to have 2 markers
  if (_.filter(markers).length < 2) return

  const { pickup, dropoff } = markers

  disableForm()

  MapHelper.route([
    [ pickup.getLatLng().lat, pickup.getLatLng().lng ],
    [ dropoff.getLatLng().lat, dropoff.getLatLng().lng ]
  ])
    .then(route => {

      var duration = parseInt(route.duration, 10)
      var distance = parseInt(route.distance, 10)

      var kms = (distance / 1000).toFixed(2)
      var minutes = Math.ceil(duration / 60)

      $('#delivery_distance').text(kms + ' Km')
      $('#delivery_duration').text(minutes + ' min')

      enableForm()

      // return decodePolyline(data.routes[0].geometry);
    })
    .catch(() => enableForm())
}

const markerIcons = {
  pickup:  { icon: 'cube', color: '#E74C3C' },
  dropoff: { icon: 'flag', color: '#2ECC71' }
}

const addressTypeSelector = {
  pickup:  { checkmark: '#delivery_pickup_checked' },
  dropoff: { checkmark: '#delivery_dropoff_checked' }
}

function createMarker(location, addressType) {
  const { icon, color } = markerIcons[addressType]
  if (markers[addressType]) {
    map.removeLayer(markers[addressType])
  }
  markers[addressType] = MapHelper.createMarker({
    lat: location.latitude,
    lng: location.longitude
  }, icon, 'marker', color)
  markers[addressType].addTo(map)

  MapHelper.fitToLayers(map, _.filter(markers))
}

function markAddressChecked(addressType) {
  const { checkmark } = addressTypeSelector[addressType]
  $(checkmark).removeClass('hidden')
}

function onLocationChange(location, addressType) {
  createMarker(location, addressType)
  refreshRouting()
}

function refreshAddressForm(type, address) {

  document.querySelector(`#delivery_${type}_address_streetAddress`).value = address.streetAddress
  document.querySelector(`#delivery_${type}_address_postalCode`).value = address.postalCode
  document.querySelector(`#delivery_${type}_address_addressLocality`).value = address.addressLocality
  document.querySelector(`#delivery_${type}_address_name`).value = address.name || ''
  document.querySelector(`#delivery_${type}_address_telephone`).value = address.telephone || ''

  let disabled = false

  if (address.id) {
    document.querySelector(`#delivery_${type}_address_id`).value = address.id
    disabled = true
  } else {
    document.querySelector(`#delivery_${type}_address_latitude`).value = address.geo.latitude
    document.querySelector(`#delivery_${type}_address_longitude`).value = address.geo.longitude
  }

  document.querySelector(`#delivery_${type}_address_postalCode`).disabled = disabled
  document.querySelector(`#delivery_${type}_address_addressLocality`).disabled = disabled
  document.querySelector(`#delivery_${type}_address_name`).disabled = disabled
  document.querySelector(`#delivery_${type}_address_telephone`).disabled = disabled

}

const baseURL = location.protocol + '//' + location.hostname

function onFormChanged() {

  const storeId = $('#delivery_store').val()

  const payload = {
    store: `/api/stores/${storeId}`,
    pickup: {
      address: {
        streetAddress: $('#delivery_pickup_address_streetAddress').val(),
        latLng: [
          $('#delivery_pickup_address_latitude').val(),
          $('#delivery_pickup_address_longitude').val(),
        ]
      },
      before: moment($('#delivery_pickup_doneBefore').val(), 'YYYY-MM-DD HH:mm:ss').format()
    },
    dropoff: {
      address: {
        streetAddress: $('#delivery_dropoff_address_streetAddress').val(),
        latLng: [
          $('#delivery_dropoff_address_latitude').val(),
          $('#delivery_dropoff_address_longitude').val(),
        ]
      },
      before: moment($('#delivery_dropoff_doneBefore').val(), 'YYYY-MM-DD HH:mm:ss').format()
    }
  }

  if (storeId
    && payload.pickup.address.streetAddress.length > 0
    && payload.dropoff.address.streetAddress.length > 0) {

    const $container = $('#delivery_price').closest('.delivery-price')

    $container.removeClass('delivery-price--error')
    $container.addClass('delivery-price--loading')
    $('#delivery_price_error').text('')

    axios({
      method: 'post',
      url: baseURL + '/api/pricing/deliveries',
      data: payload,
      headers: {
        Authorization: `Bearer ${jwt}`
      }
    })
      .then(response => {
        $('#delivery_price').text((response.data / 100).formatMoney(2, window.AppData.currencySymbol))
      })
      .catch(e => {
        if (e.response && e.response.status === 400) {
          if (e.response.data.hasOwnProperty('@type') && e.response.data['@type'] === 'hydra:Error') {
            $container.addClass('delivery-price--error')
            $('#delivery_price_error').text(e.response.data['hydra:description'])
          }
        }
      })
      .finally(() => $container.removeClass('delivery-price--loading'))
  }
}

window.initMap = function() {

  const originAddressLatitude  = document.querySelector('#delivery_pickup_address_latitude')
  const originAddressLongitude = document.querySelector('#delivery_pickup_address_longitude')

  const deliveryAddressLatitude  = document.querySelector('#delivery_dropoff_address_latitude')
  const deliveryAddressLongitude = document.querySelector('#delivery_dropoff_address_longitude')

  const hasOriginAddress = originAddressLatitude.value && originAddressLongitude.value
  const hasDeliveryAddress = deliveryAddressLongitude.value && deliveryAddressLatitude.value

  if (hasOriginAddress) {
    markAddressChecked('pickup')
    createMarker({
      latitude: originAddressLatitude.value,
      longitude: originAddressLongitude.value
    }, 'pickup')
  }

  if (hasDeliveryAddress) {
    markAddressChecked('dropoff')
    createMarker({
      latitude: deliveryAddressLatitude.value,
      longitude: deliveryAddressLongitude.value
    }, 'dropoff')
  }

  const pickupAddressWidget =
    document.querySelector('#delivery_pickup_address_streetAddress_widget')

  const pickupAddressAddresses = JSON.parse(pickupAddressWidget.dataset.addresses)

  render(
    <AddressAutosuggest
      address={ document.querySelector('#delivery_pickup_address_streetAddress').value }
      addresses={ pickupAddressAddresses }
      geohash={ '' }
      onAddressSelected={ (value, address, type) => {
        refreshAddressForm('pickup', address)
        $('#delivery_pickup_panel_title').text(address.streetAddress)
        markAddressChecked('pickup')
        onLocationChange(address.geo, 'pickup')
        onFormChanged()
      }} />,
    pickupAddressWidget
  )

  new CoopCycle.DateTimePicker(document.querySelector('#delivery_pickup_doneBefore_widget'), {
    defaultValue: document.querySelector('#delivery_pickup_doneBefore').value,
    onChange: function(date) {
      document.querySelector('#delivery_pickup_doneBefore').value = date.format('YYYY-MM-DD HH:mm:ss')
    }
  })

  const dropoffAddressWidget =
    document.querySelector('#delivery_dropoff_address_streetAddress_widget')

  const dropoffAddressAddresses = JSON.parse(dropoffAddressWidget.dataset.addresses)

  render(
    <AddressAutosuggest
      addresses={ dropoffAddressAddresses }
      address={ '' }
      geohash={ '' }
      onAddressSelected={ (value, address, type) => {
        refreshAddressForm('dropoff', address)
        $('#delivery_dropoff_panel_title').text(address.streetAddress)
        markAddressChecked('dropoff')
        onLocationChange(address.geo, 'dropoff')
        onFormChanged()
      } } />,
    dropoffAddressWidget
  )

  new CoopCycle.DateTimePicker(document.querySelector('#delivery_dropoff_doneBefore_widget'), {
    defaultValue: document.querySelector('#delivery_dropoff_doneBefore').value,
    onChange: function(date) {
      document.querySelector('#delivery_dropoff_doneBefore').value = date.format('YYYY-MM-DD HH:mm:ss')
      onFormChanged()
    }
  })

  //

  $('#delivery_store').on('change', onFormChanged)
}

$.getJSON(window.Routing.generate('profile_jwt'))
  .then(tok => {
    jwt = tok
  })

map = MapHelper.init('map')
