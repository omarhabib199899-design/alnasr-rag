import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface representing a core feature of the system.
 */
interface Feature {
  icon: string;
  title: string;
}

@Component({
  selector: 'app-feature-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './feature-grid.component.html',
  styleUrl: './feature-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class FeatureGridComponent {

  // List of product features to be displayed in the grid
  readonly features = signal<Feature[]>([
    { icon: 'cart-shopping', title: 'Fast sales' },
    { icon: 'cubes', title: 'Live inventory' },
    { icon: 'building', title: 'Multi Branches' },
    { icon: 'chart-column', title: 'Clear reports' },
    { icon: 'user-group', title: 'Customer records' },
    { icon: 'file-invoice-dollar', title: 'Invoicing' },
  ]);
}