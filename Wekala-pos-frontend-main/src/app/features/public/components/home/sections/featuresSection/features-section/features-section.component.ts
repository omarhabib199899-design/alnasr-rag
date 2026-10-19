import { ChangeDetectionStrategy, Component } from '@angular/core';
import { FeatureGridComponent } from '../../aboutSection/components/feature-grid.component/feature-grid.component';
import { FeaturesContentComponent } from '../components/features-content/features-content.component';
import { FeaturesGridComponent } from '../components/features-grid/features-grid.component';

@Component({
  selector: 'app-features',
  imports: [FeaturesGridComponent, FeaturesContentComponent],
  standalone:true,
  templateUrl: './features-section.component.html',
  styleUrl: './features-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeaturesSectionComponent {}
