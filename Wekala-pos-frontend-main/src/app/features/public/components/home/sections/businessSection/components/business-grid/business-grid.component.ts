import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface representing a specific business type supported by the platform.
 */
interface BusinessItem {
  title: string;
  description: string;
  icon: string;
  featured?: boolean;
}

@Component({
  selector: 'app-business-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './business-grid.component.html',
  styleUrl: './business-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class BusinessGridComponent {

  // List of business types compatible with the Wekala POS system
  readonly businesses = signal<BusinessItem[]>([
    {
      title: 'Restaurants & Cafés',
      description: 'Dine-in, takeaway, pickup and delivery. Tables, kitchen orders, menus, payments and daily sales in one place.',
      icon: 'utensils',
      featured: true
    },
    {
      title: 'Retail Stores',
      description: 'Products, sales, inventory, customers, suppliers, discounts and payment methods.',
      icon: 'store'
    },
    {
      title: 'Clothing Stores',
      description: 'Manage products by size, color, category, stock and barcode.',
      icon: 'shirt'
    },
    {
      title: 'E-commerce',
      description: 'Online orders, customers, shipping, payments and product management.',
      icon: 'globe'
    },
    {
      title: 'Services & Other',
      description: 'Suitable for salons, clinics and businesses that provide services.',
      icon: 'sparkle'
    }
  ]);
}