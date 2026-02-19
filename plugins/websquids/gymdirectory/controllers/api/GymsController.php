<?php

namespace Websquids\Gymdirectory\Controllers\Api;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use System\Models\File as FileModel;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\Contact;
use websquids\Gymdirectory\Models\Faq;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Hour;
use websquids\Gymdirectory\Models\Pricing;
use websquids\Gymdirectory\Models\Review;

class GymsController extends Controller {
  /**
   * Extract domain from URL
   */
  private function extractDomain($url) {
    if (empty($url)) {
      return null;
    }

    // Parse URL
    $parsed = parse_url($url);
    if (!isset($parsed['host'])) {
      return null;
    }

    $host = $parsed['host'];

    // Remove www. prefix
    $host = preg_replace('/^www\./', '', $host);

    return strtolower($host);
  }

  /**
   * Find gym by domain from contacts
   */
  private function findGymByDomain($domain) {
    if (empty($domain)) {
      return null;
    }

    // Find contacts with business_website type that match the domain
    $contacts = Contact::where('type', 'business_website')
      ->whereNotNull('value')
      ->where('gym_id', '!=', null)
      ->get();

    foreach ($contacts as $contact) {
      $contactDomain = $this->extractDomain($contact->value);
      if ($contactDomain === $domain) {
        return Gym::find($contact->gym_id);
      }
    }

    return null;
  }

  /**
   * Find or create address by lat/long for this gym.
   * If lat/long exist: find existing address with same coords or create new one.
   * If lat/long missing: create a new address (with 0,0 or from addressData).
   */
  private function findOrCreateAddress($gym, $addressData) {
    $hasLatLong = !empty($addressData['latitude']) && !empty($addressData['longitude']);

    if ($hasLatLong) {
      $lat = (float)$addressData['latitude'];
      $lng = (float)$addressData['longitude'];
      $tolerance = 0.0001; // Small tolerance for floating point comparison

      // Find existing address with similar lat/long for this gym
      $existingAddress = Address::where('gym_id', $gym->id)
        ->whereBetween('latitude', [$lat - $tolerance, $lat + $tolerance])
        ->whereBetween('longitude', [$lng - $tolerance, $lng + $tolerance])
        ->first();

      if ($existingAddress) {
        return $existingAddress;
      }
    }

    // No matching address found, or lat/long not provided: create new address
    return $this->createAddress($gym, $addressData);
  }

  /**
   * Create a new address for the gym (used when lat/long missing or no existing match).
   */
  private function createAddress($gym, $addressData) {
    $lat = isset($addressData['latitude']) && $addressData['latitude'] !== '' && $addressData['latitude'] !== null
      ? (float)$addressData['latitude'] : 0;
    $lng = isset($addressData['longitude']) && $addressData['longitude'] !== '' && $addressData['longitude'] !== null
      ? (float)$addressData['longitude'] : 0;

    // Check if this is the first address for the gym
    $isFirstAddress = $gym->addresses()->count() === 0;

    $address = new Address;
    $address->gym_id = $gym->id;
    $address->google_id = $addressData['google_id'] ?? null;
    $address->category = $addressData['category'] ?? null;
    $address->sub_category = $addressData['sub_category'] ?? null;
    $address->full_address = $addressData['full_address'] ?? null;
    $address->borough = $addressData['borough'] ?? null;
    $address->street = $addressData['street'] ?? null;
    $address->city = $addressData['city'] ?? null;
    $address->postal_code = $addressData['postal_code'] ?? null;
    $address->state = $addressData['state'] ?? null;
    $address->country = $addressData['country'] ?? null;
    $address->timezone = $addressData['timezone'] ?? null;
    $address->latitude = $lat;
    $address->longitude = $lng;
    $address->google_review_url = $addressData['google_review_url'] ?? null;
    $address->total_reviews = $addressData['total_reviews'] ?? null;
    $address->average_rating = $addressData['average_rating'] ?? null;
    $address->reviews_per_score = $addressData['reviews_per_score'] ?? null;

    // Set is_primary if this is the first address or explicitly set
    $address->is_primary = $isFirstAddress || ($addressData['is_primary'] ?? false);

    // If setting as primary, unset other primaries
    if ($address->is_primary) {
      Address::where('gym_id', $gym->id)
        ->where('id', '!=', $address->id ?? 0)
        ->update(['is_primary' => false]);
    }

    $address->save();

    return $address;
  }

  /**
   * GET /api/v1/gyms
   * List all gyms with filters and pagination.
   * Use ?fields=sitemap to get minimal data (id, slug, updated_at) for sitemap generation.
   */
  public function index(Request $request) {
    try {
      $fields = $request->input('fields');
      $sitemapOnly = ($fields === 'sitemap');
      $topGymsOnly = ($fields === 'topgyms');
      $trending = ($request->input('trending') == 'true') ? true : false;
      $popularGymsOnly = ($request->input('popular') == 'true') ? true : false;

      // Get address_id from query if specified (ignored when sitemapOnly)
      $addressId = $request->input('address_id');

      // 1. Query & Filter
      $perPage = $request->input('per_page', 12);

      if ($sitemapOnly) {
        $gyms = Gym::select(['id', 'slug', 'updated_at'])
          ->filter($request->all())
          ->paginate($perPage);
        $gyms->getCollection()->transform(function ($gym) {
          $gym->setVisible(['id', 'slug', 'updated_at']);
          return $gym;
        });
        return $gyms;
      }

      if ($topGymsOnly) {
        $state = $request->input('state') ? trim($request->input('state')) : '';
        $city = $request->input('city') ? trim($request->input('city')) : '';

        $addrQuery = Address::with(['reviews', 'gym' => function ($q) {
          $q->with(['logo', 'gallery', 'featured_image']);
        }])->whereHas('gym');

        if ($state !== '') {
          $addrQuery->where('state', $state);
        }
        if ($city !== '') {
          $addrQuery->where('city', $city);
        }

        $addresses = $addrQuery->get()->makeHidden('reviews');

        $topGyms = $addresses->groupBy('gym_id')
          ->map(function ($addrs) use ($state, $city) {
            $gym = $addrs->first()->gym;
            if (!$gym) {
              return null;
            }

            $allRates = $addrs->flatMap(function ($a) {
              return $a->reviews ? $a->reviews->pluck('rate') : collect();
            })->filter();

            $reviewCount = $allRates->count();
            $avgRating = $allRates->isNotEmpty() ? round((float) $allRates->avg(), 2) : 0;

            if ($reviewCount < 15 || $avgRating < 4) {
              return null;
            }

            $firstAddr = $addrs->first();

            // Set computed properties on gym
            $gym->rating = $avgRating;
            $gym->reviewCount = $reviewCount;
            $gym->address = $firstAddr;

            // Set featured_image logic (same as regular logic)
            if ($gym->featured_image) {
              $gym->featured_image = $gym->featured_image;
            } else {
              $latestGalleryImage = $gym->gallery ? $gym->gallery->sortByDesc('created_at')->first() : null;
              $gym->featured_image = $latestGalleryImage ? $latestGalleryImage : null;
            }

            $gym->filterType = ($state && $city) ? 'state' : (($state) ? 'state' : 'city');

            // Use setVisible to match regular logic
            $gym->setVisible([
              'id',
              'slug',
              'trending',
              'name',
              'description',
              'city',
              'state',
              'rating',
              'reviewCount',
              'logo',
              'gallery',
              'featured_image',
              'address',
              'filterType',
            ]);

            return $gym;
          })
          ->filter()
          ->sortByDesc('rating')
          ->take(10)
          ->values();

        return response()->json(['data' => $topGyms]);
      }

      if ($popularGymsOnly) {
        $popularGyms = Gym::with(['logo', 'gallery', 'addresses'])
          ->where('is_popular', 1)
          ->limit(5)
          ->get();

        $popularGyms->transform(function ($gym) use ($addressId) {
          $address = null;
          if ($addressId) {
            $address = $gym->addresses()->where('id', $addressId)->first();
          }
          if (!$address) {
            $address = $gym->getPrimaryAddress();
          }

          if ($address) {
            $reviewsCount = $address->reviews()->count();
            $reviewsAvg = $address->reviews()->avg('rate');
          } else {
            $reviewsCount = 0;
            $reviewsAvg = 0;
          }

          $gym->rating = $reviewsAvg ? round((float)$reviewsAvg, 2) : 0;
          $gym->reviewCount = $reviewsCount;
          $gym->address = $address;

          if ($gym->featured_image) {
            $gym->featured_image = $gym->featured_image;
          } else {
            $latestGalleryImage = $gym->gallery ? $gym->gallery->sortByDesc('created_at')->first() : null;
            $gym->featured_image = $latestGalleryImage ? $latestGalleryImage : null;
          }

          $gym->setVisible([
            'id',
            'slug',
            'trending',
            'name',
            'description',
            'city',
            'state',
            'rating',
            'reviewCount',
            'logo',
            'gallery',
            'featured_image',
            'address',
          ]);

          return $gym;
        });

        return response()->json(['data' => $popularGyms]);
      }

      $query = Gym::with(['logo', 'gallery', 'addresses'])
        ->filter($request->all());

      if ($trending) {
        $query->where('trending', 1);
      }

      $gyms = $query->paginate($perPage);

      // 2. Transform Data
      $gyms->getCollection()->transform(function ($gym) use ($addressId) {
        // Determine target address
        $address = null;
        if ($addressId) {
          $address = $gym->addresses()->where('id', $addressId)->first();
        }
        if (!$address) {
          $address = $gym->getPrimaryAddress();
        }

        // Load related data for the address
        if ($address) {
          $reviewsCount = $address->reviews()->count();
          $reviewsAvg = $address->reviews()->avg('rate');
        } else {
          $reviewsCount = 0;
          $reviewsAvg = 0;
        }

        // Calculate Rating
        $gym->rating = $reviewsAvg ? round((float)$reviewsAvg, 2) : 0;
        $gym->reviewCount = $reviewsCount;
        $gym->address = $address;

        if ($gym->featured_image) {
          $gym->featured_image = $gym->featured_image;
        } else {
          $latestGalleryImage = $gym->gallery ? $gym->gallery->sortByDesc('created_at')->first() : null;
          $gym->featured_image = $latestGalleryImage ? $latestGalleryImage : null;
        }

        // Cleanup Output
        $gym->setVisible([
          'id',
          'slug',
          'trending',
          'name',
          'description',
          'city',
          'state',
          'rating',
          'reviewCount',
          'logo',
          'gallery',
          'featured_image',
          'address'
        ]);

        return $gym;
      });

      return $gyms;
    } catch (ValidationException $e) {
      return response()->json([
        'error' => 'Validation failed',
        'message' => $e->getMessage(),
        'errors' => $e->errors()
      ], 422);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@index: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/states
   * Distinct states from gyms table with counts (for state filter autocomplete).
   */
  public function states(Request $request) {
    try {
      $stateNames = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
      ];
      $rows = Gym::selectRaw('state, count(*) as count')
        ->whereNotNull('state')
        ->where('state', '!=', '')
        ->groupBy('state')
        ->orderBy('state')
        ->get();
      $data = $rows->map(function ($row) use ($stateNames) {
        return [
          'state' => $row->state,
          'stateName' => $stateNames[$row->state] ?? $row->state,
          'count' => (int) $row->count,
        ];
      });
      return response()->json($data);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@states: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/locations
   * Locations (city, state, postal_code) with address counts for autocomplete.
   * Each row has all three so dropdown can show "City, State Zipcode" or "Zipcode - City, State".
   * Optional ?q= filters by city, state or postal_code. Sorted by count desc.
   */
  public function locations(Request $request) {
    try {
      $q = $request->input('q');
      $qTrim = $q ? trim($q) : '';

      $query = Address::selectRaw('city, state, postal_code, count(*) as count')
        ->whereNotNull('city')
        ->where('city', '!=', '')
        ->whereNotNull('state')
        ->where('state', '!=', '')
        ->whereNotNull('postal_code')
        ->where('postal_code', '!=', '')
        ->groupBy('city', 'state', 'postal_code')
        ->orderByRaw('count(*) desc');

      if ($qTrim !== '') {
        $query->where(function ($q) use ($qTrim) {
          $q->where('city', 'like', '%' . $qTrim . '%')
            ->orWhere('state', 'like', '%' . $qTrim . '%')
            ->orWhere('postal_code', 'like', '%' . $qTrim . '%');
        });
      }

      $out = [];
      foreach ($query->limit(50)->get() as $row) {
        $out[] = [
          'label' => trim($row->city . ', ' . $row->state . ' ' . $row->postal_code),
          'city' => $row->city,
          'state' => $row->state,
          'postal_code' => $row->postal_code,
          'count' => (int) $row->count,
        ];
      }

      return response()->json($out);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@locations: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/addresses-by-location
   * Addresses filtered by city and/or postal_code, grouped by gym.
   * Query params: city (optional), postal_code (optional), search (optional, matches city OR postal_code). At least one required.
   */
  public function addressesByLocation(Request $request) {
    try {
      $city = $request->input('city');
      $postalCode = $request->input('postal_code');
      $search = $request->input('search');

      $cityTrim = $city ? trim($city) : '';
      $postalTrim = $postalCode ? trim($postalCode) : '';
      $searchTrim = $search ? trim($search) : '';

      if ($cityTrim === '' && $postalTrim === '' && $searchTrim === '') {
        return response()->json(['data' => []]);
      }

      $query = Address::with(['gym' => function ($q) {
        $q->with('logo');
      }, 'reviews'])
        ->orderByRaw('is_primary DESC')
        ->orderBy('id');

      if ($cityTrim !== '') {
        $query->where('city', 'like', '%' . $cityTrim . '%');
      }
      if ($postalTrim !== '') {
        $query->where('postal_code', 'like', '%' . $postalTrim . '%');
      }
      if ($searchTrim !== '') {
        $query->where(function ($q) use ($searchTrim) {
          $q->where('city', 'like', '%' . $searchTrim . '%')
            ->orWhere('postal_code', 'like', '%' . $searchTrim . '%');
        });
      }

      $addresses = $query->get();

      $grouped = $addresses->groupBy('gym_id')->map(function ($addrs, $gymId) {
        $firstAddr = $addrs->first();
        $gym = $firstAddr->gym;
        if (!$gym) {
          return null;
        }
        $reviewsCount = $addrs->sum(function ($a) {
          return $a->reviews ? $a->reviews->count() : 0;
        });
        $allRates = $addrs->flatMap(function ($a) {
          return $a->reviews ? $a->reviews->pluck('rate') : collect();
        })->filter();
        $rating = $allRates->isNotEmpty() ? round((float) $allRates->avg(), 2) : 0;
        $logoPath = $gym->logo ? $gym->logo->getPath() : null;
        return [
          'gym' => [
            'id' => $gym->id,
            'name' => $gym->name,
            'slug' => $gym->slug,
            'logo' => $logoPath ? ['path' => $logoPath, 'alt' => $gym->name] : null,
            'rating' => $rating,
            'reviewCount' => $reviewsCount,
            'review_count' => $reviewsCount,
            'city' => $firstAddr->city,
            'state' => $firstAddr->state,
          ],
          'addresses' => $addrs->map(function ($a) {
            return $a->toArray();
          })->values()->all(),
        ];
      })->filter(function ($item) {
        return $item !== null;
      })->values()->all();

      return response()->json(['data' => $grouped]);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@addressesByLocation: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/{gym_id}/addresses
   * Paginated list of addresses for a gym (default 5 per page).
   * Optional query params: city, postal_code (filter addresses by location).
   */
  public function addresses($gym_id, Request $request) {
    try {
      $gym = Gym::find($gym_id);
      if (!$gym) {
        return response()->json([
          'error' => 'Not found',
          'message' => 'Gym not found',
        ], 404);
      }

      $perPage = (int) $request->input('per_page', 500);
      $perPage = max(1, min(500, $perPage)); // clamp between 1 and 100

      $query = Address::where('gym_id', $gym_id);

      $city = $request->input('city');
      $postalCode = $request->input('postal_code');
      if ($city && trim($city) !== '') {
        $query->where('city', 'like', '%' . trim($city) . '%');
      }
      if ($postalCode && trim($postalCode) !== '') {
        $query->where('postal_code', 'like', '%' . trim($postalCode) . '%');
      }

      $paginator = $query->orderByRaw('is_primary DESC')->orderBy('id')->paginate($perPage);

      return response()->json($paginator);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@addresses: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/addresses/{address_id}
   * Get single address details
   */
  public function address($address_id, Request $request) {
    try {
      $address = Address::with([
        'gym',
        'contacts',
        'hours',
        'reviews',
        'pricing'
      ])->find($address_id);
      if (!$address) {
        return response()->json([
          'error' => 'Not found',
          'message' => 'Address not found',
        ], 404);
      }
      return response()->json($address->toArray());
    } catch (\Exception $e) {
      Log::error('Error in GymsController@address: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/{slug}
   * Get single gym details
   */
  public function show($slug, Request $request) {
    try {
      // Get address_id from query if specified
      $addressId = $request->input('address_id');

      $gym = Gym::with([
        // 'addresses.contacts',
        // 'addresses.hours',
        // 'addresses.reviews',
        // 'addresses.pricing',
        // 'contacts',
        'faqs',
        'logo',
        'gallery',
        'featured_image',
      ])
        ->where('slug', $slug)
        ->firstOrFail();

      // Determine target address
      $address = null;
      if ($addressId) {
        $address = $gym->addresses()->where('id', $addressId)->first();
      }
      if (!$address) {
        $address = $gym->getPrimaryAddress();
      }

      // Load related data for the address
      if ($address) {
        $gym->hours = $address->hours;
        $gym->reviews = $address->reviews;
        $gym->pricing = $address->pricing;
        $gym->rating = $address->reviews()->avg('rate') ? round((float)$address->reviews()->avg('rate'), 2) : 0;
      } else {
        $gym->hours = collect([]);
        $gym->reviews = collect([]);
        $gym->pricing = collect([]);
        $gym->rating = 0;
      }

      $gym->address = $address;
      $gym->addresses_count = $gym->addresses()->count();
      $gym->contacts_count = $gym->contacts()->count();

      return response()->json($gym->toArray());
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
      return response()->json([
        'error' => 'Not found',
        'message' => 'Gym not found'
      ], 404);
    } catch (ValidationException $e) {
      return response()->json([
        'error' => 'Validation failed',
        'message' => $e->getMessage(),
        'errors' => $e->errors()
      ], 422);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@show: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * POST /api/v1/gyms
   * Create or update a gym
   */
  public function store(Request $request) {
    try {
      // Handle array input
      $inputData = $request->all();
      $gymsData = [];

      if (isset($inputData[0]) && is_array($inputData[0])) {
        // Array of gyms
        $gymsData = $inputData;
      } elseif (isset($inputData['gym']) && is_array($inputData['gym'])) {
        // Single gym object
        $gymsData = [$inputData];
      } else {
        // Direct gym data
        $gymsData = [$inputData];
      }

      $results = [];

      foreach ($gymsData as $gymInput) {
        // Handle nested structure if data is under 'gym' key
        $requestData = $gymInput;
        if (isset($requestData['gym']) && is_array($requestData['gym'])) {
          $requestData = array_merge($requestData, $requestData['gym']);
        }

        // Extract domain for duplicate checking
        // First check if domain is provided directly
        $domain = null;
        if (isset($requestData['domain']) && !empty($requestData['domain'])) {
          $domain = $this->extractDomain($requestData['domain']);
        }
        // If not provided directly, extract from contacts
        if (!$domain && isset($requestData['contacts']) && is_array($requestData['contacts'])) {
          foreach ($requestData['contacts'] as $contact) {
            if (isset($contact['type']) && $contact['type'] === 'business_website' && !empty($contact['value'])) {
              $domain = $this->extractDomain($contact['value']);
              break;
            }
          }
        }

        // Find existing gym by domain
        $gym = null;
        if ($domain) {
          $gym = $this->findGymByDomain($domain);
        }

        // Validate gym and all related records using merged requestData
        $validator = Validator::make($requestData, [
          // Gym - after merge, these should be at top level
          'name'        => 'required|string|max:255',
          'description' => 'nullable',
          'city'        => 'required|string|max:255',
          'state'       => 'required|string|max:255',
          'trending'    => 'sometimes|boolean',
          'featured'    => 'sometimes|boolean',
          'domain'      => 'nullable|string',
          'google_place_url' => 'nullable|string',
          'business_name' => 'nullable|string|max:255',
          'website_built_with' => 'nullable|string',
          'website_title' => 'nullable|string|max:255',
          'website_desc' => 'nullable|string',
          'logo' => 'nullable|string',
          'featured_image' => 'nullable|string',

          // Address
          'address' => 'sometimes|array',
          'address.latitude' => 'required_with:address|numeric',
          'address.longitude' => 'required_with:address|numeric',
          'address.google_id' => 'nullable|string',
          'address.category' => 'nullable|string',
          'address.sub_category' => 'nullable|string',
          'address.full_address' => 'nullable|string',
          'address.borough' => 'nullable|string',
          'address.street' => 'nullable|string',
          'address.city' => 'nullable|string',
          'address.postal_code' => 'nullable|alpha_num',
          'address.state' => 'nullable|string',
          'address.country' => 'nullable|string',
          'address.timezone' => 'nullable|string',
          'address.google_review_url' => 'nullable|string',
          'address.total_reviews' => 'nullable|integer',
          'address.average_rating' => 'nullable|numeric',
          'address.reviews_per_score' => 'nullable',
          'address.is_primary' => 'nullable|boolean',

          // Hours (hasMany)
          'hours'            => 'sometimes|array',
          'hours.*.day'      => 'required_with:hours|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
          'hours.*.from'     => 'required_with:hours|date_format:H:i',
          'hours.*.to'       => 'required_with:hours|date_format:H:i',

          // Reviews (hasMany)
          'reviews'              => 'sometimes|array',
          'reviews.*.reviewer'   => 'required_with:reviews|string|max:255',
          'reviews.*.rate'       => 'required_with:reviews|numeric|min:0|max:5',
          'reviews.*.text'       => 'nullable|string',
          'reviews.*.reviewed_at' => 'nullable|string',
          'reviews.*.google_review_id' => 'nullable|string',
          'reviews.*.reviewer_name' => 'nullable|string|max:255',
          'reviews.*.is_local_guide' => 'nullable|boolean',
          'reviews.*.reviews_amount' => 'nullable|integer',
          'reviews.*.photos_amount' => 'nullable|integer',
          'reviews.*.reviewer_link' => 'nullable|string',
          'reviews.*.rating' => 'nullable|integer|min:0|max:5',
          'reviews.*.date' => 'nullable|date',
          'reviews.*.photos' => 'nullable|string',

          // FAQs (hasMany)
          'faqs'               => 'sometimes|array',
          'faqs.*.category'    => 'required_with:faqs|string',
          'faqs.*.question'    => 'required_with:faqs|string',
          'faqs.*.answer'      => 'required_with:faqs|string',

          // Pricing (hasMany)
          'pricing'               => 'sometimes|array',
          'pricing.*.tier_name'   => 'required_with:pricing|string|max:255',
          'pricing.*.price'       => 'required_with:pricing|string',
          'pricing.*.frequency'   => 'nullable|string',
          'pricing.*.description' => 'nullable',

          // Contacts
          'contacts' => 'sometimes|array',
          'contacts.*.type' => 'required_with:contacts|in:business_website,business_phone,email,facebook,twitter,instagram,youtube,linkedin,contact_page',
          'contacts.*.value' => 'nullable|string',
        ]);

        if ($validator->fails()) {
          throw new ValidationException($validator);
        }

        $data = $validator->validated();

        // Ensure we get values from merged data (handle both nested and flat)
        $name = $data['name'] ?? ($requestData['gym']['name'] ?? null);
        $city = $data['city'] ?? ($requestData['gym']['city'] ?? null);
        $state = $data['state'] ?? ($requestData['gym']['state'] ?? null);
        $description = $data['description'] ?? ($requestData['gym']['description'] ?? '');

        // Update or create gym
        if ($gym) {
          // Update existing gym
          $gym->name = $name;
          // Handle description - convert array to string if needed
          if (is_array($description)) {
            $description = !empty($description) ? implode(' ', $description) : '';
          }
          $gym->description = is_string($description) ? $description : '';
          $gym->city = $city;
          $gym->state = $state;
          // Handle featured/trending - featured takes precedence
          $gym->trending = isset($data['featured']) ? $data['featured'] : ($data['trending'] ?? false);
          $gym->google_place_url = $data['google_place_url'] ?? null;
          $gym->business_name = $data['business_name'] ?? null;
          $gym->website_built_with = $data['website_built_with'] ?? null;
          $gym->website_title = $data['website_title'] ?? null;
          $gym->website_desc = $data['website_desc'] ?? null;
          $gym->save();
        } else {
          // Create new gym
          $gym = new Gym;
          $gym->name = $name;
          // Handle description - convert array to string if needed
          if (is_array($description)) {
            $description = !empty($description) ? implode(' ', $description) : '';
          }
          $gym->description = is_string($description) ? $description : '';
          $gym->city = $city;
          $gym->state = $state;
          // Handle featured/trending - featured takes precedence
          $gym->trending = isset($data['featured']) ? $data['featured'] : ($data['trending'] ?? false);
          $gym->google_place_url = $data['google_place_url'] ?? null;
          $gym->business_name = $data['business_name'] ?? null;
          $gym->website_built_with = $data['website_built_with'] ?? null;
          $gym->website_title = $data['website_title'] ?? null;
          $gym->website_desc = $data['website_desc'] ?? null;
          $gym->save();
        }

        // Download and attach logo if URL provided
        if (!empty($data['logo']) && filter_var($data['logo'], FILTER_VALIDATE_URL)) {
          try {
            $logoFile = new FileModel;
            $logoFile->fromUrl($data['logo']);
            $logoFile->is_public = true;
            $logoFile->save();
            $gym->logo()->add($logoFile);
          } catch (\Exception $e) {
            Log::error('Failed to download logo: ' . $e->getMessage());
          }
        }

        // Download and attach featured_image if URL provided
        if (!empty($data['featured_image']) && filter_var($data['featured_image'], FILTER_VALIDATE_URL)) {
          try {
            // Download for featured_image
            $featuredFile = new FileModel;
            $featuredFile->fromUrl($data['featured_image']);
            $featuredFile->is_public = true;
            $featuredFile->save();
            $gym->featured_image()->add($featuredFile);

            // Download again for gallery
            $galleryFile = new FileModel;
            $galleryFile->fromUrl($data['featured_image']);
            $galleryFile->is_public = true;
            $galleryFile->save();
            $gym->gallery()->add($galleryFile);
          } catch (\Exception $e) {
            Log::error('Failed to download featured image: ' . $e->getMessage());
          }
        }

        // Extract hours, reviews, pricing data - check both merged data and nested structure
        $hoursData = $data['hours'] ?? ($requestData['gym']['hours'] ?? ($requestData['hours'] ?? null));
        $reviewsData = $data['reviews'] ?? ($requestData['gym']['reviews'] ?? ($requestData['reviews'] ?? null));
        $pricingData = $data['pricing'] ?? ($requestData['gym']['pricing'] ?? ($requestData['pricing'] ?? null));

        // Handle address - check both merged data and nested structure
        $address = null;
        $addressData = $data['address'] ?? ($requestData['gym']['address'] ?? ($requestData['address'] ?? null));

        if (!empty($addressData) && is_array($addressData)) {
          $address = $this->findOrCreateAddress($gym, $addressData);
        }

        // If hours/reviews are provided but no address exists, create a default address
        if (!$address && (!empty($hoursData) || !empty($reviewsData) || !empty($pricingData))) {
          // Create a minimal address using gym's city/state
          $addressData = [
            'latitude' => 0,
            'longitude' => 0,
            'city' => $city,
            'state' => $state,
            'full_address' => $city . ', ' . $state,
            'is_primary' => true
          ];
          $address = $this->findOrCreateAddress($gym, $addressData);
        }

        // Create or Update Hours (linked to address)
        if (!empty($hoursData) && is_array($hoursData) && $address) {
          foreach ($hoursData as $hourData) {
            $day = $hourData['day'] ?? 'monday';

            // Check if hour already exists for this address and day
            $hour = Hour::where('address_id', $address->id)
              ->where('day', $day)
              ->first();

            if (!$hour) {
              $hour = new Hour;
              $hour->address_id = $address->id;
              $hour->day = $day;
            }

            $hour->from = $hourData['from'] ?? '09:00';
            $hour->to = $hourData['to'] ?? '17:00';
            $hour->save();
          }
        }

        // Create Reviews (linked to address)
        if (!empty($reviewsData) && is_array($reviewsData) && $address) {
          foreach ($reviewsData as $reviewData) {
            $review = new Review;
            $review->reviewer = $reviewData['reviewer'] ?? '';
            $review->rate = $reviewData['rate'] ?? 0;
            $review->text = $reviewData['text'] ?? '';
            $review->reviewed_at = $reviewData['reviewed_at'] ?? null;
            $review->google_review_id = $reviewData['google_review_id'] ?? null;
            $review->reviewer_name = $reviewData['reviewer_name'] ?? null;
            $review->is_local_guide = $reviewData['is_local_guide'] ?? false;
            $review->reviews_amount = $reviewData['reviews_amount'] ?? null;
            $review->photos_amount = $reviewData['photos_amount'] ?? null;
            $review->reviewer_link = $reviewData['reviewer_link'] ?? null;
            $review->rating = $reviewData['rating'] ?? null;
            $review->date = $reviewData['date'] ?? null;
            // Handle photos as JSON string
            $photos = $reviewData['photos'] ?? null;
            if ($photos !== null) {
              if (is_string($photos)) {
                $decoded = json_decode($photos, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                  $review->photos = $decoded;
                } else {
                  $review->photos = [];
                }
              } elseif (is_array($photos)) {
                $review->photos = $photos;
              } else {
                $review->photos = [];
              }
            } else {
              $review->photos = null;
            }
            $review->address_id = $address->id;
            $review->save();
          }
        }

        // Create FAQs (still linked to gym)
        if (!empty($data['faqs']) && is_array($data['faqs'])) {
          foreach ($data['faqs'] as $faqData) {
            $faq = new Faq;
            $faq->category = $faqData['category'] ?? 'general';
            $faq->question = $faqData['question'] ?? '';
            $faq->answer = $faqData['answer'] ?? '';
            $faq->gym_id = $gym->id;
            $faq->save();
          }
        }

        // Create Pricing Tiers (linked to address)
        if (!empty($pricingData) && is_array($pricingData) && $address) {
          foreach ($pricingData as $tier) {
            $price = new Pricing;
            $price->tier_name = $tier['tier_name'] ?? 'Standard';
            $price->price = $tier['price'] ?? '0';
            // Handle frequency - use default if empty or just whitespace
            $frequency = isset($tier['frequency']) ? trim($tier['frequency']) : '';
            $price->frequency = !empty($frequency) ? $frequency : 'month';
            // Handle description - convert array to string if needed
            $description = $tier['description'] ?? '';
            if (is_array($description)) {
              $description = !empty($description) ? implode(' ', $description) : '';
            }
            $price->description = is_string($description) ? $description : '';
            $price->address_id = $address->id;
            $price->save();
          }
        }

        // Create Contacts (linked to gym)
        // First, create business_website contact from domain if provided
        if ($domain && !empty($requestData['domain'])) {
          // Check if contact already exists
          $existingContact = Contact::where('gym_id', $gym->id)
            ->where('type', 'business_website')
            ->where('value', $requestData['domain'])
            ->first();

          if (!$existingContact) {
            $contact = new Contact;
            $contact->type = 'business_website';
            $contact->value = $requestData['domain'];
            $contact->gym_id = $gym->id;
            $contact->save();
          }
        }

        // Create other contacts (ensure we have an address when linking contacts to it)
        if (!empty($data['contacts']) && is_array($data['contacts']) && $address) {
          foreach ($data['contacts'] as $contactData) {
            // Skip business_website if we already created it from domain
            if ($contactData['type'] === 'business_website' && $domain && !empty($requestData['domain'])) {
              continue;
            }

            $contact = new Contact;
            $contact->type = $contactData['type'];
            $contact->value = $contactData['value'] ?? null;
            $contact->address_id = $address->id;
            $contact->save();
          }
        }

        $results[] = [
          'message' => $gym->wasRecentlyCreated ? 'Gym Created Successfully' : 'Gym Updated Successfully',
          'id' => $gym->id,
          'slug' => $gym->slug
        ];
      }

      return response()->json(
        count($results) === 1 ? $results[0] : $results,
        count($results) === 1 && isset($results[0]['message']) && strpos($results[0]['message'], 'Created') !== false ? 201 : 200
      );
    } catch (ValidationException $e) {
      return response()->json([
        'error' => 'Validation failed',
        'message' => $e->getMessage(),
        'errors' => $e->errors()
      ], 422);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@store: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
        'request_data' => $request->all()
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/cities-and-states
   * All city+state combinations with gym counts.
   */
  public function citiesAndStates(Request $request) {
    try {
      $stateNames = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
      ];

      $stateRows = Gym::selectRaw('state, count(*) as count')
        ->whereNotNull('state')
        ->where('state', '!=', '')
        ->groupBy('state')
        ->orderBy('state')
        ->get();
      $states = $stateRows->map(function ($row) use ($stateNames) {
        return [
          'state' => $row->state,
          'stateName' => $stateNames[$row->state] ?? $row->state,
          'count' => (int) $row->count,
        ];
      });

      $cityRows = Gym::selectRaw('city, count(*) as count')
        ->whereNotNull('city')
        ->where('city', '!=', '')
        ->groupBy('city')
        ->orderByDesc('count')
        ->limit(50)
        ->get()
        ->sortBy('city')
        ->values();
      $cities = $cityRows->map(function ($row) {
        return [
          'city' => $row->city,
          'count' => (int) $row->count,
        ];
      });

      return response()->json([
        'cities' => $cities,
        'states' => $states,
      ]);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@citiesAndStates: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/filtered-top-gyms
   * Filtered top gyms.
   * Optional ?state= filters by state name&city= filter by city name.
   */
  public function filteredTopGyms(Request $request) {
    try {
      $stateNames = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
      ];

      $stateInput = $request->input('state');
      $cityInput = $request->input('city');

      // Support multiple values: comma-separated string or array
      $states = [];
      if ($stateInput) {
        $states = is_array($stateInput) ? $stateInput : explode(',', $stateInput);
        $states = array_filter(array_map('trim', $states), fn($v) => $v !== '');
      }

      $cities = [];
      if ($cityInput) {
        $cities = is_array($cityInput) ? $cityInput : explode(',', $cityInput);
        $cities = array_filter(array_map('trim', $cities), fn($v) => $v !== '');
      }

      $query = Gym::selectRaw('city, state')
        ->whereNotNull('city')
        ->where('city', '!=', '')
        ->whereNotNull('state')
        ->where('state', '!=', '')
        ->groupBy('city', 'state')
        ->orderByRaw('count(DISTINCT id) DESC')
        ->orderBy('state')
        ->orderBy('city');

      if (!empty($states)) {
        $query->where(function ($sub) use ($states, $stateNames) {
          foreach ($states as $stateTrim) {
            $sub->orWhere('state', 'like', '%' . $stateTrim . '%');

            foreach ($stateNames as $abbr => $fullName) {
              if (stripos($fullName, $stateTrim) !== false) {
                $sub->orWhere('state', $abbr);
              }
            }
          }
        });
      }

      if (!empty($cities)) {
        $query->where(function ($sub) use ($cities) {
          foreach ($cities as $cityTrim) {
            $sub->orWhere('city', 'like', '%' . $cityTrim . '%');
          }
        });
      }

      $perPage = (int) $request->input('per_page', 50);
      $page = (int) $request->input('page', 1);
      $perPage = max(1, min(100, $perPage));
      $page = max(1, $page);

      $topGyms = [];

      if (!empty($states) || !empty($cities)) {
        // User applied a filter — show matching city/state entries
        $rows = $query->get();

        $seenCities = [];
        $seenStates = [];
        foreach ($rows as $row) {
          // When state filter is given, include the state label AND all cities in that state
          if (!empty($states) && !isset($seenStates[$row->state])) {
            $seenStates[$row->state] = true;
            $topGyms[] = [
              'label' => 'Best Gyms in ' . ($stateNames[$row->state] ?? $row->state),
              'type' => 'state',
              'filter' => $row->state,
            ];
          }
          if (!isset($seenCities[$row->city])) {
            $seenCities[$row->city] = true;
            $topGyms[] = [
              'label' => 'Best Gyms in ' . $row->city,
              'type' => 'city',
              'filter' => $row->city,
            ];
          }
        }
      } else {
        // Default — show a mix of top cities and top states
        $cityRows = Gym::selectRaw('city, count(DISTINCT id) as count')
          ->whereNotNull('city')
          ->where('city', '!=', '')
          ->groupBy('city')
          ->orderByDesc('count')
          ->get();

        foreach ($cityRows as $row) {
          $topGyms[] = [
            'label' => 'Best Gyms in ' . $row->city,
            'type' => 'city',
            'filter' => $row->city,
          ];
        }

        $stateRows = Gym::selectRaw('state, count(DISTINCT id) as count')
          ->whereNotNull('state')
          ->where('state', '!=', '')
          ->groupBy('state')
          ->orderByDesc('count')
          ->get();

        foreach ($stateRows as $row) {
          $topGyms[] = [
            'label' => 'Best Gyms in ' . ($stateNames[$row->state] ?? $row->state),
            'type' => 'state',
            'filter' => $row->state,
          ];
        }
      }

      $total = count($topGyms);

      $sliced = array_slice($topGyms, ($page - 1) * $perPage, $perPage);

      $paginator = new LengthAwarePaginator($sliced, $total, $perPage, $page);

      $result = $paginator->toArray();
      unset($result['links']);

      return response()->json($result);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@filteredTopGyms: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * GET /api/v1/gyms/highly-rated
   * Gyms with average rating > 4.5 and 20+ reviews for the homepage.
   */
  public function highlyRated() {
    try {
      $addresses = Address::with(['reviews', 'gym' => function ($q) {
        $q->with(['logo', 'gallery', 'featured_image']);
      }])->whereHas('gym')->get();

      $gyms = $addresses->groupBy('gym_id')
        ->map(function ($addrs) {
          $gym = $addrs->first()->gym;
          if (!$gym) {
            return null;
          }

          $allRates = $addrs->flatMap(function ($a) {
            return $a->reviews ? $a->reviews->pluck('rate') : collect();
          })->filter();

          $reviewCount = $allRates->count();
          $avgRating = $allRates->isNotEmpty() ? round((float) $allRates->avg(), 2) : 0;

          if ($reviewCount < 20 || $avgRating <= 4.5) {
            return null;
          }

          $firstAddr = $addrs->first();

          $gym->rating = $avgRating;
          $gym->reviewCount = $reviewCount;
          $gym->address = $firstAddr->makeHidden('reviews');

          if (!$gym->featured_image) {
            $latestGalleryImage = $gym->gallery ? $gym->gallery->sortByDesc('created_at')->first() : null;
            $gym->featured_image = $latestGalleryImage ?: null;
          }

          $gym->setVisible([
            'id',
            'slug',
            'trending',
            'name',
            'description',
            'city',
            'state',
            'rating',
            'reviewCount',
            'logo',
            'gallery',
            'featured_image',
            'address',
          ]);

          return $gym;
        })
        ->filter()
        ->sortByDesc('rating')
        ->values();

      return response()->json(['data' => $gyms]);
    } catch (\Exception $e) {
      Log::error('Error in GymsController@highlyRated: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
      ]);
      return response()->json([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }
}
