<?php namespace websquids\Gymdirectory\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;
use websquids\Gymdirectory\Models\Gym;
use websquids\Gymdirectory\Models\Address;
use websquids\Gymdirectory\Models\Review;
use websquids\Gymdirectory\Models\Hour;
use websquids\Gymdirectory\Models\Pricing;

class MigrateGymIdToAddressId extends Migration
{
    public function up()
    {
        // First, ensure all gyms with reviews/hours/pricing have addresses
        $this->ensureGymsHaveAddresses();
        
        // Migrate Reviews
        $this->migrateReviews();
        
        // Migrate Hours
        $this->migrateHours();
        
        // Migrate Pricing
        $this->migratePricing();
        
        // Fix any remaining orphaned records
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
            
            // If we have a target address, check for duplicates before updating
            if ($targetAddressId) {
                // Check if an hour already exists for this address and day
                $existingHour = Hour::where('address_id', $targetAddressId)
                    ->where('day', $hour->day)
                    ->where('id', '!=', $hour->id)
                    ->first();
                
                if ($existingHour) {
                    // Duplicate exists - keep the existing one, delete this duplicate
                    // Or merge: keep the one with more complete data
                    if ($hour->from && $hour->to && (!$existingHour->from || !$existingHour->to)) {
                        // This hour has more complete data, update the existing one
                        $existingHour->from = $hour->from;
                        $existingHour->to = $hour->to;
                        $existingHour->save();
                    }
                    // Delete the duplicate
                    $hour->delete();
                } else {
                    // No duplicate, safe to update
                    $hour->address_id = $targetAddressId;
                    $hour->save();
                }
            } else {
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
     */
    private function ensureGymsHaveAddresses()
    {
        // Get all unique address_ids from reviews, hours, and pricing that point to gyms (not addresses)
        $reviewGymIds = \DB::table('websquids_gymdirectory_reviews')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        $hourGymIds = \DB::table('websquids_gymdirectory_hours')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        $pricingGymIds = \DB::table('websquids_gymdirectory_pricing')
            ->select('address_id')
            ->whereNotNull('address_id')
            ->whereNotIn('address_id', function($query) {
                $query->select('id')->from('websquids_gymdirectory_addresses');
            })
            ->pluck('address_id')
            ->unique();
        
        // Combine all gym IDs that need addresses
        $gymIdsNeedingAddresses = $reviewGymIds->merge($hourGymIds)->merge($pricingGymIds)->unique();
        
        foreach ($gymIdsNeedingAddresses as $gymId) {
            $gym = Gym::find($gymId);
            if ($gym && $gym->addresses()->count() === 0) {
                // Create address for this gym
                $this->createAddressFromGym($gym);
            }
        }
        
        // Also check: if any gym has data pointing to it (address_id = gym_id) but no addresses, create one
        // This is already handled above, but we also need to check gyms that might have been missed
        // Get all gym IDs that exist
        $allGymIds = Gym::pluck('id');
        
        foreach ($allGymIds as $gymId) {
            $gym = Gym::find($gymId);
            if (!$gym) {
                continue;
            }
            
            // Check if this gym ID appears as address_id in any table (meaning it's broken)
            $hasBrokenReviews = \DB::table('websquids_gymdirectory_reviews')
                ->where('address_id', $gymId)
                ->exists();
            
            $hasBrokenHours = \DB::table('websquids_gymdirectory_hours')
                ->where('address_id', $gymId)
                ->exists();
            
            $hasBrokenPricing = \DB::table('websquids_gymdirectory_pricing')
                ->where('address_id', $gymId)
                ->exists();
            
            // If gym has broken references but no addresses, create one
            if (($hasBrokenReviews || $hasBrokenHours || $hasBrokenPricing) && $gym->addresses()->count() === 0) {
                $this->createAddressFromGym($gym);
            }
        }
    }
    
    /**
     * Fix orphaned records (records with null address_id that should be linked)
     */
    private function fixOrphanedRecords()
    {
        // Find reviews with null address_id and try to link them
        $orphanedReviews = Review::whereNull('address_id')->get();
        foreach ($orphanedReviews as $review) {
            // Try to find gym through any relationship
            // Since we can't determine the original gym, we'll leave these as null
            // They can be manually fixed or re-linked through the admin panel
        }
        
        // Find hours with null address_id
        $orphanedHours = Hour::whereNull('address_id')->get();
        foreach ($orphanedHours as $hour) {
            // Similar to reviews - can't determine original gym
        }
        
        // Find pricing with null address_id
        $orphanedPricing = Pricing::whereNull('address_id')->get();
        foreach ($orphanedPricing as $price) {
            // Similar to reviews - can't determine original gym
        }
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

