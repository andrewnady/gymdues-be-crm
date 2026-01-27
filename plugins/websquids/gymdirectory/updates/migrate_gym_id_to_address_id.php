<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Illuminate\Support\Facades\DB;
use Winter\Storm\Database\Updates\Migration;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\Review;
use websquids\Gymdirectory\Models\Hour;
use websquids\Gymdirectory\Models\Pricing;

/**
 * Migration: Migrate existing gym_id data to address_id for reviews, hours, and pricing
 *
 * Purpose:
 * - Create an address for each gym that has no address but has data (Hours, Reviews, Pricing)
 * - Link old data (Hours, Reviews, Pricing) to the gym's primary address
 *
 * Process:
 * 1. Ensure all gyms with data have at least one address (create if missing)
 * 2. Migrate reviews: Update address_id from gym_id to the gym's primary address_id
 * 3. Migrate hours: Update address_id from gym_id to the gym's primary address_id (handle duplicates)
 * 4. Migrate pricing: Update address_id from gym_id to the gym's primary address_id
 * 5. Clean up any orphaned records
 */
class MigrateGymIdToAddressId extends Migration
{
    public function up()
    {
        // Step 1: Ensure all gyms with reviews/hours/pricing have addresses
        // This creates addresses for gyms that need them before we migrate data
        $this->ensureGymsHaveAddresses();
        
        // Step 2: Migrate Reviews - link to gym's primary address
        $this->migrateReviews();
        
        // Step 3: Migrate Hours - link to gym's primary address (handles duplicates)
        $this->migrateHours();
        
        // Step 4: Migrate Pricing - link to gym's primary address
        $this->migratePricing();
        
        // Step 5: Fix any remaining orphaned records
        $this->fixOrphanedRecords();
    }
    
    private function migrateReviews()
    {
        // Get all reviews
        $reviews = Review::all();
        
        foreach ($reviews as $review) {
            // If address_id is null, try to find gym from address relationship
            if (!$review->address_id) {
                // Try to get gym from address relationship (if it exists)
                $address = $review->address;
                if ($address && $address->gym) {
                    $primaryAddress = $address->gym->getPrimaryAddress();
                    if ($primaryAddress) {
                        $review->address_id = $primaryAddress->id;
                        $review->save();
                    }
                }
                continue;
            }
            
            // Check if address_id points to a gym (incorrect)
            $gym = Gym::find($review->address_id);
            
            if ($gym) {
                // This is actually a gym_id, get the primary address
                $primaryAddress = $gym->getPrimaryAddress();
                
                if ($primaryAddress) {
                    $review->address_id = $primaryAddress->id;
                    $review->save();
                } else {
                    // If no address exists, create one from gym data
                    $address = $this->createAddressFromGym($gym);
                    if ($address) {
                        $review->address_id = $address->id;
                        $review->save();
                    }
                }
            } else {
                // Check if it's a valid address_id
                $address = Address::find($review->address_id);
                if (!$address) {
                    // Invalid address_id, try to find gym from old data
                    // This handles cases where the review might have been linked to a gym
                    // but we can't determine which one - we'll leave it null for now
                    $review->address_id = null;
                    $review->save();
                }
            }
        }
    }
    
    private function migrateHours()
    {
        $hours = Hour::all();
        $hourMappings = []; // Store hour_id => target_address_id mapping
        
        // First pass: determine target address for each hour
        foreach ($hours as $hour) {
            $targetAddressId = null;
            
            // If address_id is null, try to find gym from address relationship
            if (!$hour->address_id) {
                $address = $hour->address;
                if ($address && $address->gym) {
                    $primaryAddress = $address->gym->getPrimaryAddress();
                    if ($primaryAddress) {
                        $targetAddressId = $primaryAddress->id;
                    }
                }
            } else {
                $gym = Gym::find($hour->address_id);
                
                if ($gym) {
                    $primaryAddress = $gym->getPrimaryAddress();
                    
                    if ($primaryAddress) {
                        $targetAddressId = $primaryAddress->id;
                    } else {
                        $address = $this->createAddressFromGym($gym);
                        if ($address) {
                            $targetAddressId = $address->id;
                        }
                    }
                } else {
                    $address = Address::find($hour->address_id);
                    if ($address) {
                        $targetAddressId = $hour->address_id; // Already correct
                    }
                }
            }
            
            if ($targetAddressId) {
                $hourMappings[$hour->id] = [
                    'address_id' => $targetAddressId,
                    'day' => $hour->day,
                    'hour' => $hour
                ];
            }
        }
        
        // Group hours by target address_id + day to find duplicates
        $groups = [];
        foreach ($hourMappings as $hourId => $mapping) {
            $key = $mapping['address_id'] . '-' . $mapping['day'];
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $mapping['hour'];
        }
        
        // Process each group: keep the best hour, delete duplicates
        foreach ($groups as $key => $groupHours) {
            if (count($groupHours) > 1) {
                // Multiple hours for same address+day - keep the best one
                // Sort by completeness (hours with from/to are better)
                usort($groupHours, function($a, $b) {
                    $aComplete = !empty($a->from) && !empty($a->to);
                    $bComplete = !empty($b->from) && !empty($b->to);
                    
                    if ($aComplete && !$bComplete) {
                        return -1;
                    }
                    if (!$aComplete && $bComplete) {
                        return 1;
                    }
                    
                    // If both complete or both incomplete, keep the first one (lowest ID)
                    return $a->id <=> $b->id;
                });
                
                // Keep the first (best) one, delete the rest
                array_shift($groupHours); // Keep first one, process the rest as duplicates
                foreach ($groupHours as $duplicateHour) {
                    $duplicateHour->delete();
                    // Remove from mappings so we don't try to update it
                    unset($hourMappings[$duplicateHour->id]);
                }
            }
        }
        
        // Now update all remaining hours
        foreach ($hourMappings as $hourId => $mapping) {
            $hour = Hour::find($hourId);
            if ($hour) {
                // Double-check no duplicate exists (safety check)
                $existing = Hour::where('address_id', $mapping['address_id'])
                    ->where('day', $mapping['day'])
                    ->where('id', '!=', $hour->id)
                    ->first();
                
                if (!$existing) {
                    $hour->address_id = $mapping['address_id'];
                    $hour->save();
                } else {
                    // Duplicate found (shouldn't happen, but handle it)
                    $hour->delete();
                }
            }
        }
        
        // Handle hours that didn't get a target address
        foreach ($hours as $hour) {
            if (!isset($hourMappings[$hour->id]) && $hour->exists) {
                // No valid address found, set to null
                $hour->address_id = null;
                $hour->save();
            }
        }
    }
    
    private function migratePricing()
    {
        $pricing = Pricing::all();
        
        foreach ($pricing as $price) {
            // If address_id is null, try to find gym from address relationship
            if (!$price->address_id) {
                $address = $price->address;
                if ($address && $address->gym) {
                    $primaryAddress = $address->gym->getPrimaryAddress();
                    if ($primaryAddress) {
                        $price->address_id = $primaryAddress->id;
                        $price->save();
                    }
                }
                continue;
            }
            
            $gym = Gym::find($price->address_id);
            
            if ($gym) {
                $primaryAddress = $gym->getPrimaryAddress();
                
                if ($primaryAddress) {
                    $price->address_id = $primaryAddress->id;
                    $price->save();
                } else {
                    $address = $this->createAddressFromGym($gym);
                    if ($address) {
                        $price->address_id = $address->id;
                        $price->save();
                    }
                }
            } else {
                $address = Address::find($price->address_id);
                if (!$address) {
                    $price->address_id = null;
                    $price->save();
                }
            }
        }
    }
    
    /**
     * Ensure all gyms that have reviews, hours, or pricing have at least one address
     * This method finds ALL gyms that have any data and ensures they have addresses
     */
    private function ensureGymsHaveAddresses()
    {
        // Step 1: Find all gym IDs that have data pointing to them (address_id = gym_id)
        // These are the broken records that need fixing
        $reviewGymIds = DB::table('websquids_gymdirectory_reviews')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        $hourGymIds = DB::table('websquids_gymdirectory_hours')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        $pricingGymIds = DB::table('websquids_gymdirectory_pricing')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        // Combine all gym IDs that have broken references
        $gymIdsWithBrokenData = $reviewGymIds->merge($hourGymIds)->merge($pricingGymIds)->unique();
        
        // Step 2: Also find gyms that might have data but address_id is null
        // We need to check all gyms to see if they have any data at all
        $allGyms = Gym::all();
        $gymsNeedingAddresses = [];
        
        foreach ($allGyms as $gym) {
            // Check if gym has any data (reviews, hours, or pricing)
            // either pointing to it (broken) or null (orphaned)
            $hasBrokenReviews = DB::table('websquids_gymdirectory_reviews')
                ->where('address_id', $gym->id)
                ->exists();
            
            $hasBrokenHours = DB::table('websquids_gymdirectory_hours')
                ->where('address_id', $gym->id)
                ->exists();
            
            $hasBrokenPricing = DB::table('websquids_gymdirectory_pricing')
                ->where('address_id', $gym->id)
                ->exists();
            
            // Check for orphaned data (null address_id) - we can't determine which gym these belong to
            // but if a gym has no addresses and we find orphaned data, we'll create an address
            // This is a best-effort approach
            
            // If gym has broken references or is in our list, it needs an address
            if (($hasBrokenReviews || $hasBrokenHours || $hasBrokenPricing || $gymIdsWithBrokenData->contains($gym->id))
                && $gym->addresses()->count() === 0) {
                $gymsNeedingAddresses[] = $gym;
            }
        }
        
        // Step 3: Create addresses for all gyms that need them
        foreach ($gymsNeedingAddresses as $gym) {
            $this->createAddressFromGym($gym);
        }
    }
    
    /**
     * Fix orphaned records (records with null address_id that should be linked)
     */
    private function fixOrphanedRecords()
    {
        // Find reviews with null address_id and try to link them
        // Since we can't determine the original gym, we'll leave these as null
        // They can be manually fixed or re-linked through the admin panel
        Review::whereNull('address_id')->get();
        
        // Find hours with null address_id
        // Similar to reviews - can't determine original gym
        Hour::whereNull('address_id')->get();
        
        // Find pricing with null address_id
        // Similar to reviews - can't determine original gym
        Pricing::whereNull('address_id')->get();
    }
    
    /**
     * Create an address from gym data if no address exists
     */
    private function createAddressFromGym(Gym $gym)
    {
        // Check if gym already has addresses
        if ($gym->addresses()->count() > 0) {
            return $gym->getPrimaryAddress();
        }
        
        // Create a new address from gym data
        $address = new Address();
        $address->gym_id = $gym->id;
        $address->street = $gym->address ?? '';
        $address->city = $gym->city ?? '';
        $address->state = $gym->state ?? '';
        $address->postal_code = $gym->zipCode ?? '';
        $address->full_address = trim(($gym->address ?? '') . ', ' . ($gym->city ?? '') . ', ' . ($gym->state ?? '') . ' ' . ($gym->zipCode ?? ''), ', ');
        $address->is_primary = true;
        $address->save();
        
        return $address;
    }
    
    public function down()
    {
        // This migration is not easily reversible
        // You would need to store the original gym_id before migration
        // For now, we'll leave it empty
    }
}

